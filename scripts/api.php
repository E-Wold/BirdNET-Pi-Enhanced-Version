<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/scripts/common.php');

$config = get_config();
set_timezone();
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

if ($requestMethod !== 'GET') {
  sendResponse405();
}

$db = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

function api_json($data, $status = 200) {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data);
}

function api_format_service($service) {
  $status = trim(shell_exec('systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null'));
  if ($status === '') {
    $status = 'unknown';
  }
  return [
    'name' => $service,
    'status' => $status,
    'ok' => $status === 'active'
  ];
}

function api_weather_label($code) {
  $codes = [
    0 => 'Clear', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
    45 => 'Fog', 48 => 'Rime fog', 51 => 'Light drizzle', 53 => 'Moderate drizzle',
    55 => 'Dense drizzle', 61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
    71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow', 80 => 'Slight showers',
    81 => 'Moderate showers', 82 => 'Violent showers', 95 => 'Thunderstorm'
  ];
  return $codes[$code] ?? 'Cloudy';
}

function api_ebird_export_count($db, $date, $min_confidence = 0.75) {
  $stmt = $db->prepare("
    SELECT COUNT(*) AS row_count, COALESCE(SUM(DetectionCount), 0) AS detection_count
    FROM (
      SELECT Com_Name, CAST(substr(Time, 1, 2) AS INTEGER) AS Hour, COUNT(*) AS DetectionCount
      FROM detections
      WHERE Date = :date
        AND Confidence > :min_confidence
        AND Time IS NOT NULL
        AND length(Time) >= 2
      GROUP BY Com_Name, CAST(substr(Time, 1, 2) AS INTEGER)
    )
  ");
  $stmt->bindValue(':date', $date, SQLITE3_TEXT);
  $stmt->bindValue(':min_confidence', $min_confidence, SQLITE3_FLOAT);
  $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  return [
    'row_count' => (int)($row['row_count'] ?? 0),
    'detection_count' => (int)($row['detection_count'] ?? 0)
  ];
}

if (preg_match('#^/api/v1/system/health$#', $requestUri)) {
  $home = get_home();
  $db_path = __ROOT__ . '/scripts/birds.db';
  $last_detection = $db->querySingle('SELECT Date || " " || Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1');
  $weather_count = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'") ? $db->querySingle("SELECT COUNT(*) FROM weather WHERE Date = DATE('now','localtime')") : 0;
  $disk_total = @disk_total_space($home);
  $disk_free = @disk_free_space($home);

  api_json([
    'services' => [
      'recording' => api_format_service('birdnet_recording.service'),
      'analysis' => api_format_service('birdnet_analysis.service')
    ],
    'disk' => [
      'total_bytes' => $disk_total ?: 0,
      'free_bytes' => $disk_free ?: 0,
      'used_percent' => ($disk_total && $disk_free) ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : null
    ],
    'database' => [
      'path' => $db_path,
      'size_bytes' => file_exists($db_path) ? filesize($db_path) : 0
    ],
    'last_detection_at' => $last_detection ?: null,
    'weather_rows_today' => (int)$weather_count,
    'generated_at' => date('c')
  ]);

} elseif (preg_match('#^/api/v1/weather/current$#', $requestUri)) {
  $has_weather = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'") > 0;
  if (!$has_weather) {
    api_json(['status' => 'missing', 'message' => 'Weather table has not been created yet.']);
    exit;
  }

  $has_is_day = false;
  $cols = $db->query("PRAGMA table_info(weather)");
  while ($cols && ($col = $cols->fetchArray(SQLITE3_ASSOC))) {
    if ($col['name'] === 'IsDay') {
      $has_is_day = true;
      break;
    }
  }

  $sel = $has_is_day ? 'Date, Hour, Temp, ConditionCode, IsDay' : 'Date, Hour, Temp, ConditionCode';
  $stmt = $db->prepare("SELECT $sel FROM weather WHERE Date = DATE('now','localtime') AND Hour = :hour AND Temp IS NOT NULL LIMIT 1");
  $stmt->bindValue(':hour', (int)date('G'), SQLITE3_INTEGER);
  $current = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
  $latest = $db->query("SELECT Date, Hour FROM weather WHERE Temp IS NOT NULL ORDER BY Date DESC, Hour DESC LIMIT 1")->fetchArray(SQLITE3_ASSOC);
  $today_rows = $db->querySingle("SELECT COUNT(*) FROM weather WHERE Date = DATE('now','localtime') AND Temp IS NOT NULL") ?: 0;

  if (!$current) {
    api_json([
      'status' => 'missing',
      'today_rows' => (int)$today_rows,
      'last_synced_at' => $latest ? $latest['Date'] . ' ' . sprintf('%02d:00', (int)$latest['Hour']) : null,
      'message' => 'Current-hour weather is missing.'
    ]);
    exit;
  }

  $code = (int)$current['ConditionCode'];
  api_json([
    'status' => 'current',
    'date' => $current['Date'],
    'hour' => (int)$current['Hour'],
    'temp' => round((float)$current['Temp']),
    'condition_code' => $code,
    'condition' => api_weather_label($code),
    'is_day' => $has_is_day ? (int)$current['IsDay'] : 1,
    'today_rows' => (int)$today_rows,
    'last_synced_at' => $current['Date'] . ' ' . sprintf('%02d:00', (int)$current['Hour']),
    'generated_at' => date('c')
  ]);

} elseif (preg_match('#^/api/v1/species/list$#', $requestUri)) {
  $limit = request_int($_GET, 'limit', 50, 1, 100);
  $offset = request_int($_GET, 'offset', 0, 0, 1000000);
  $time_period = isset($_GET['time_period']) ? $_GET['time_period'] : 'all';
  $sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'detections';
  $search = isset($_GET['search']) ? trim($_GET['search']) : '';

  $where_clauses = [];
  if ($time_period !== 'all') {
    $periods = ['24h' => '-1 day', '7d' => '-7 days', '30d' => '-30 days', '90d' => '-90 days', '1y' => '-1 year'];
    if (isset($periods[$time_period])) {
      $where_clauses[] = "Date >= date('now', '" . $periods[$time_period] . "')";
    }
  }
  if ($search !== '') {
    $where_clauses[] = "(Com_Name LIKE :search OR Sci_Name LIKE :search)";
  }
  $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

  $order_by = 'COUNT(*) DESC';
  if ($sort_by === 'sci_name') $order_by = 'Sci_Name ASC';
  elseif ($sort_by === 'com_name') $order_by = 'Com_Name ASC';
  elseif ($sort_by === 'confidence') $order_by = 'MAX(Confidence) DESC';

  $count_stmt = $db->prepare("SELECT COUNT(*) AS total FROM (SELECT Sci_Name FROM detections $where_sql GROUP BY Sci_Name)");
  $list_stmt = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConf, MIN(Date) as FirstDate FROM detections $where_sql GROUP BY Sci_Name ORDER BY $order_by LIMIT :limit OFFSET :offset");
  if ($search !== '') {
    $count_stmt->bindValue(':search', '%' . $search . '%', SQLITE3_TEXT);
    $list_stmt->bindValue(':search', '%' . $search . '%', SQLITE3_TEXT);
  }
  $list_stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
  $list_stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
  $total = (int)$count_stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'];
  $result = $list_stmt->execute();
  $items = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $info = get_info_url($row['Sci_Name']);
    $items[] = [
      'common_name' => $row['Com_Name'],
      'scientific_name' => $row['Sci_Name'],
      'detections' => (int)$row['Count'],
      'max_confidence' => round((float)$row['MaxConf'], 4),
      'first_detected' => $row['FirstDate'],
      'info_url' => $info['URL'],
      'info_title' => $info['TITLE'],
      'wikipedia_url' => 'https://wikipedia.org/wiki/' . str_replace('%20', '_', rawurlencode($row['Sci_Name']))
    ];
  }
  api_json([
    'items' => $items,
    'count' => count($items),
    'limit' => $limit,
    'offset' => $offset,
    'next_offset' => $offset + count($items),
    'total' => $total,
    'has_more' => ($offset + count($items)) < $total
  ]);

} elseif (preg_match('#^/api/v1/exports/ebird/preview$#', $requestUri)) {
  $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
  $counts = api_ebird_export_count($db, $date);
  $warnings = [];
  $lat = $config['LATITUDE'] ?? '';
  $lon = $config['LONGITUDE'] ?? '';
  if ($counts['row_count'] === 0) {
    $warnings[] = 'No detections above 75% confidence were found for this date.';
  }
  if ($lat === '' || $lon === '' || $lat === '0.000' || $lon === '0.000') {
    $warnings[] = 'Latitude or longitude is missing from Settings.';
  }
  api_json([
    'date' => $date,
    'row_count' => $counts['row_count'],
    'detection_count' => $counts['detection_count'],
    'latitude' => $lat,
    'longitude' => $lon,
    'warnings' => $warnings,
    'ok' => empty($warnings)
  ]);

} elseif (preg_match('#^/api/v1/image/(\S+)$#', $requestUri, $matches)) {
  $flickr = new Flickr();
  $wikipedia = new Wikipedia();
  if ($config["IMAGE_PROVIDER"] === 'FLICKR') {
    $image_provider = $flickr;
    $fallback_provider = $wikipedia;
  } else {
    $image_provider = $wikipedia;
    $fallback_provider = $flickr;
  }
  $sci_name = urldecode($matches[1]);
  $result = $image_provider->get_image($sci_name, $fallback_provider);

  if ($result == false) {
    http_response_code(404);
    echo "Error 404! No image found!";
  } else {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
      "status" => "success",
      "message" => "successfully image data from database",
      "data" => $result
    ]);
  }
} elseif (preg_match('#^/api/v1/analytics/activity$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  $stmt = $db->prepare('SELECT strftime("%H", Time) as Hour, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days") GROUP BY Hour ORDER BY Hour ASC');
  $result = $stmt->execute();
  $data = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data[$row['Hour']] = $row['Count'];
  }
  
  // Fill empty hours with 0
  $final_data = [];
  for ($i = 0; $i < 24; $i++) {
    $hourStr = str_pad($i, 2, '0', STR_PAD_LEFT);
    $final_data[] = ["hour" => $hourStr, "count" => isset($data[$hourStr]) ? $data[$hourStr] : 0];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($final_data);

} elseif (preg_match('#^/api/v1/analytics/stats$#', $requestUri)) {
  $days = request_int($_GET, 'days', 7, 1, 3650);
  
  // Total detections
  $stmt = $db->prepare('SELECT COUNT(*) as total FROM detections WHERE Date >= DATE("now", "-'.$days.' days")');
  $total = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'];
  
  // Unique species
  $stmt = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) as unique_species FROM detections WHERE Date >= DATE("now", "-'.$days.' days")');
  $unique = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['unique_species'];
  
  // Avg confidence
  $stmt = $db->prepare('SELECT AVG(Confidence) as avg_conf FROM detections WHERE Date >= DATE("now", "-'.$days.' days")');
  $avg_conf = $stmt->execute()->fetchArray(SQLITE3_ASSOC)['avg_conf'];
  
  // Most common
  $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as count FROM detections WHERE Date >= DATE("now", "-'.$days.' days") GROUP BY Sci_Name ORDER BY count DESC LIMIT 1');
  $most_common = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode([
    "total_detections" => $total,
    "unique_species" => $unique,
    "avg_confidence" => round($avg_conf * 100, 1) . '%',
    "most_common" => $most_common ? $most_common['Com_Name'] : 'None',
    "most_common_count" => $most_common ? $most_common['count'] : 0,
    "days" => $days
  ]);

} elseif (preg_match('#^/api/v1/analytics/new_species$#', $requestUri)) {
  $days = request_int($_GET, 'days', 7, 1, 3650);
  
  // Find species whose FIRST detection was within the last N days
  $stmt = $db->prepare('SELECT Com_Name, Sci_Name, MIN(Date) as first_date, MIN(Time) as first_time FROM detections GROUP BY Sci_Name HAVING first_date >= DATE("now", "-'.$days.' days") ORDER BY first_date DESC, first_time DESC');
  $result = $stmt->execute();
  $data = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data[] = $row;
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/analytics/diversity$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  $stmt = $db->prepare('SELECT Date, COUNT(DISTINCT(Sci_Name)) as count FROM detections WHERE Date >= DATE("now", "-'.$days.' days") GROUP BY Date ORDER BY Date ASC');
  $result = $stmt->execute();
  
  $dates = [];
  $counts = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $dates[] = $row['Date'];
    $counts[] = $row['count'];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode(["dates" => $dates, "counts" => $counts]);

} elseif (preg_match('#^/api/v1/analytics/detections$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  $stmt = $db->prepare('SELECT Date, COUNT(*) as count FROM detections WHERE Date >= DATE("now", "-'.$days.' days") GROUP BY Date ORDER BY Date ASC');
  $result = $stmt->execute();
  
  $dates = [];
  $counts = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $dates[] = $row['Date'];
    $counts[] = $row['count'];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode(["dates" => $dates, "counts" => $counts]);

} elseif (preg_match('#^/api/v1/analytics/top_species$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  $limit = request_int($_GET, 'limit', 10, 1, 100);
  
  $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days") GROUP BY Com_Name ORDER BY Count DESC LIMIT '.$limit);
  $result = $stmt->execute();
  $data = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data[] = ["species" => $row['Com_Name'], "count" => $row['Count']];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/analytics/trends$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  // Get target species: either from GET param or default to top 5
  $target_species = [];
  if (isset($_GET['species']) && !empty($_GET['species'])) {
    $target_species = explode(',', $_GET['species']);
    // Limit to 5 to prevent performance issues and chart clutter
    if (count($target_species) > 5) {
      $target_species = array_slice($target_species, 0, 5);
    }
  } else {
    $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days") GROUP BY Com_Name ORDER BY Count DESC LIMIT 5');
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
      $target_species[] = $row['Com_Name'];
    }
  }
  
  $data = [];
  $dates_array = [];
  
  for ($i = $days; $i >= 0; $i--) {
    $dates_array[] = date('Y-m-d', strtotime("-$i days"));
  }

  // Get daily counts for each target species
  foreach ($target_species as $species) {
    $stmt = $db->prepare('SELECT Date, COUNT(*) as Count FROM detections WHERE Com_Name = :com_name AND Date >= DATE("now", "-'.$days.' days") GROUP BY Date');
    $stmt->bindValue(':com_name', $species, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $species_data = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
      $species_data[$row['Date']] = $row['Count'];
    }
    
    // Fill empty dates with 0
    $final_species_data = [];
    foreach ($dates_array as $dateStr) {
      $final_species_data[] = isset($species_data[$dateStr]) ? $species_data[$dateStr] : 0;
    }
    
    $data[$species] = $final_species_data;
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode(["dates" => $dates_array, "series" => $data]);

} elseif (preg_match('#^/api/v1/analytics/patterns$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  // Get target species: either from GET param or default to top 5
  $target_species = [];
  if (isset($_GET['species']) && !empty($_GET['species'])) {
    $target_species = explode(',', $_GET['species']);
    if (count($target_species) > 5) {
      $target_species = array_slice($target_species, 0, 5);
    }
  } else {
    $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days") GROUP BY Com_Name ORDER BY Count DESC LIMIT 5');
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
      $target_species[] = $row['Com_Name'];
    }
  }
  
  $data = [];
  foreach ($target_species as $species) {
    $stmt = $db->prepare('SELECT strftime("%H", Time) as Hour, COUNT(*) as count FROM detections WHERE Com_Name = :com_name AND Date >= DATE("now", "-'.$days.' days") GROUP BY Hour ORDER BY Hour ASC');
    $stmt->bindValue(':com_name', $species, SQLITE3_TEXT);
    $result = $stmt->execute();
    
    $hourly_counts = array_fill(0, 24, 0);
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
      $hourly_counts[intval($row['Hour'])] = (float)$row['count'] / $days; // Average detections per hour
    }
    $data[$species] = $hourly_counts;
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/detections/recent$#', $requestUri)) {
  $limit = request_int($_GET, 'limit', 20, 1, 100);
  $days = request_int($_GET, 'days', 0, 0, 3650);
  
  $date_filter = $days > 0 ? 'Date >= DATE("now", "-'.$days.' days")' : 'Date = DATE("now", "localtime")';
  $stmt = $db->prepare('SELECT Com_Name, Sci_Name, Confidence, Date, Time FROM detections WHERE '.$date_filter.' ORDER BY Date DESC, Time DESC LIMIT '.$limit);
  $result = $stmt->execute();
  $data = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data[] = [
      "species" => $row['Com_Name'],
      "sci_name" => $row['Sci_Name'],
      "confidence" => round((float)$row['Confidence'], 4),
      "date" => $row['Date'],
      "time" => $row['Time']
    ];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/detections/timeline$#', $requestUri)) {
  $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');

  $stmt = $db->prepare('SELECT Com_Name, Sci_Name, Confidence, Time, File_Name FROM detections WHERE Date = :date ORDER BY Time ASC');
  $stmt->bindValue(':date', $date, SQLITE3_TEXT);
  $result = $stmt->execute();

  $all = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $all[] = $row;
  }

  // Group by hour
  $hours_data = [];
  $total_detections = 0;
  $species_set = [];
  $hour_counts = [];

  foreach ($all as $det) {
    $hour = intval(substr($det['Time'], 0, 2));
    if (!isset($hours_data[$hour])) $hours_data[$hour] = [];
    $hours_data[$hour][] = $det;
    $total_detections++;
    $species_set[$det['Sci_Name']] = true;
    $hour_counts[$hour] = ($hour_counts[$hour] ?? 0) + 1;
  }

  // Find peak hour
  $peak_hour = 0;
  $peak_count = 0;
  foreach ($hour_counts as $h => $c) {
    if ($c > $peak_count) { $peak_count = $c; $peak_hour = $h; }
  }

  // Cluster same-species detections within 5-minute windows per hour
  $hours_result = [];
  for ($h = 0; $h < 24; $h++) {
    $dets = $hours_data[$h] ?? [];
    if (empty($dets)) continue;

    $clusters = [];
    $used = array_fill(0, count($dets), false);

    for ($i = 0; $i < count($dets); $i++) {
      if ($used[$i]) continue;
      $used[$i] = true;

      $cluster_dets = [$dets[$i]];
      $sci = $dets[$i]['Sci_Name'];
      $last_time_secs = _time_to_secs($dets[$i]['Time']);

      for ($j = $i + 1; $j < count($dets); $j++) {
        if ($used[$j]) continue;
        if ($dets[$j]['Sci_Name'] !== $sci) continue;
        $t = _time_to_secs($dets[$j]['Time']);
        if ($t - $last_time_secs <= 300) { // 5 minutes
          $used[$j] = true;
          $cluster_dets[] = $dets[$j];
          $last_time_secs = $t;
        }
      }

      // Find best confidence in cluster
      $best_conf = 0;
      $det_list = [];
      foreach ($cluster_dets as $cd) {
        $conf = round((float)$cd['Confidence'], 4);
        if ($conf > $best_conf) $best_conf = $conf;
        $det_list[] = [
          'time' => $cd['Time'],
          'confidence' => $conf,
          'file' => $cd['File_Name']
        ];
      }

      $clusters[] = [
        'species' => $cluster_dets[0]['Com_Name'],
        'sci_name' => $sci,
        'count' => count($cluster_dets),
        'best_confidence' => $best_conf,
        'first_time' => $cluster_dets[0]['Time'],
        'last_time' => $cluster_dets[count($cluster_dets) - 1]['Time'],
        'detections' => $det_list
      ];
    }

    $hours_result[] = [
      'hour' => $h,
      'detection_count' => count($dets),
      'clusters' => $clusters
    ];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode([
    'date' => $date,
    'total_detections' => $total_detections,
    'total_species' => count($species_set),
    'peak_hour' => $peak_hour,
    'hours' => $hours_result
  ]);

} elseif (preg_match('#^/api/v1/species/search$#', $requestUri)) {
  $query = isset($_GET['q']) ? trim($_GET['q']) : '';
  if (strlen($query) < 2) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
  }
  
  $stmt = $db->prepare('SELECT DISTINCT Com_Name as name, Sci_Name as sciName FROM detections WHERE Com_Name LIKE :query OR Sci_Name LIKE :query ORDER BY Com_Name ASC LIMIT 20');
  $stmt->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
  $result = $stmt->execute();
  
  $data = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $data[] = $row;
  }
  
  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} else {
  http_response_code(404);
  echo json_encode(["status" => "error", "message" => "Error 404! No route found!"]);
}

function _time_to_secs($time_str) {
  $parts = explode(':', $time_str);
  return intval($parts[0]) * 3600 + intval($parts[1]) * 60 + intval($parts[2] ?? 0);
}

function sendResponse405() {
  http_response_code(405);
  echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
  exit;
}
