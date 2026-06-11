<?php
// The "Now" home screen: what's happening in the yard right now.
// Hero + KPIs hydrate from /api/v1/dashboard/now; Today's Story is computed
// server-side with a notability gate (it only speaks when something deviates
// from this station's own baseline). Species cards carry an hour axis with
// weather, echoing the classic overview heatmap.
error_reporting(E_ERROR);
require_once 'scripts/common.php';

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

function build_todays_story($db) {
  $lines = [];
  $now_time = date('H:i:s');

  // Baseline: average detections up to this time of day over the previous 14 days
  $baseline = (float) db_query_single_safe($db,
    "SELECT AVG(c) FROM (SELECT COUNT(*) AS c FROM detections WHERE Date >= DATE('now','localtime','-14 days') AND Date < DATE('now','localtime') AND Time <= '" . SQLite3::escapeString($now_time) . "' GROUP BY Date)",
    0, 'story baseline');
  // Bounded to the current time so it compares like-for-like with the baseline
  $today_count = (int) db_query_single_safe($db,
    "SELECT COUNT(*) FROM detections WHERE Date = DATE('now','localtime') AND Time <= '" . SQLite3::escapeString($now_time) . "'", 0, 'story today');

  // Brand-new lifetime species today
  $new_species = [];
  $res = db_query_safe($db, "SELECT Com_Name FROM detections WHERE Date = DATE('now','localtime') AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now','localtime')) GROUP BY Sci_Name LIMIT 3", 'story new species');
  while ($row = db_fetch_assoc_safe($res)) {
    $new_species[] = $row['Com_Name'];
  }
  if (!empty($new_species)) {
    $lines[] = ['icon' => 'bird', 'text' => count($new_species) === 1
      ? 'A brand new species for your station: ' . $new_species[0] . '!'
      : 'New species for your station today: ' . implode(', ', $new_species) . '!'];
  }

  // Species returning after at least two weeks away
  $returns = [];
  $res = db_query_safe($db, "SELECT Com_Name, CAST(JULIANDAY(DATE('now','localtime')) - JULIANDAY(MAX(Date)) AS INTEGER) AS gap FROM detections WHERE Date < DATE('now','localtime') AND Sci_Name IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date = DATE('now','localtime')) GROUP BY Sci_Name HAVING gap >= 14 ORDER BY gap DESC LIMIT 3", 'story returns');
  while ($row = db_fetch_assoc_safe($res)) {
    $returns[] = $row['Com_Name'] . ' (last heard ' . $row['gap'] . ' days ago)';
  }
  if (!empty($returns)) {
    $lines[] = ['icon' => 'send', 'text' => 'Back after time away: ' . implode('; ', $returns) . '.'];
  }

  // Rare visitors: heard today, five or fewer lifetime detections, not new today
  $rare = [];
  $res = db_query_safe($db, "SELECT Com_Name, COUNT(*) AS lifetime FROM detections WHERE Sci_Name IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date = DATE('now','localtime')) GROUP BY Sci_Name HAVING lifetime <= 5 AND MIN(Date) < DATE('now','localtime') LIMIT 3", 'story rare');
  while ($row = db_fetch_assoc_safe($res)) {
    $rare[] = $row['Com_Name'];
  }
  if (!empty($rare)) {
    $lines[] = ['icon' => 'search', 'text' => 'Rare visitor' . (count($rare) > 1 ? 's' : '') . ' today: ' . implode(', ', $rare) . ' — worth a listen in Review.'];
  }

  // Volume: only speak when the baseline is meaningful AND deviation is large
  if ($baseline >= 20) {
    $ratio = $today_count / max(1, $baseline);
    if ($ratio >= 1.3) {
      $lines[] = ['icon' => 'trending-up', 'text' => 'A busy day: activity is ' . round(($ratio - 1) * 100) . '% above your two-week average for this time of day.'];
    } elseif ($ratio <= 0.7 && (int)date('G') >= 8) {
      $lines[] = ['icon' => 'cloud', 'text' => 'Quieter than usual: activity is ' . round((1 - $ratio) * 100) . '% below your two-week average for this time of day.'];
    }
  }

  if (empty($lines)) {
    if ($baseline < 5) {
      $lines[] = ['icon' => 'home', 'text' => 'Your station is still learning what a normal day sounds like here.'];
    } else {
      $lines[] = ['icon' => 'home', 'text' => 'A typical day so far — steady activity, nothing unusual to report.'];
    }
  }
  return $lines;
}

// Story is cached in 10-minute buckets so dawn-rush detections don't force a
// recompute on every page load.
$story_key = birdnet_cache_key('todays_story', date('Y-m-d'), date('G'), intdiv((int)date('i'), 10), filemtime(__FILE__));
$story_html = birdnet_cache_get($story_key, 900);
if ($story_html === false) {
  $story_html = '';
  foreach (build_todays_story($db) as $line) {
    $story_html .= '<li>' . nav_icon($line['icon']) . '<span>' . h($line['text']) . '</span></li>';
  }
  birdnet_cache_put($story_key, $story_html);
}

$summary = get_summary();
$visits_today = count(get_visits($db, []));
$gap_minutes = (int) round(get_visit_gap_seconds() / 60);
$visit_explainer = 'A visit groups repeated detections of the same bird. After ' . $gap_minutes
  . ' quiet minute' . ($gap_minutes === 1 ? '' : 's') . ' without that species, the next detection starts a new visit.';
?>
<div class="now-page">
  <section class="now-story ui-card" aria-label="Today's story">
    <h3><?php echo nav_icon('zap'); ?> Today's Story</h3>
    <ul class="story-lines"><?php echo $story_html; ?></ul>
  </section>

  <div class="now-main">
    <section class="now-hero ui-card" id="nowHero" aria-label="Latest detection">
      <div class="hero-photo" id="heroPhoto"><div class="hero-photo-placeholder"><?php echo nav_icon('bird'); ?></div></div>
      <div class="hero-body">
        <div class="hero-kicker"><span class="live-dot" aria-hidden="true"></span> LAST HEARD</div>
        <h2 id="heroSpecies">Listening&hellip;</h2>
        <div class="hero-sci" id="heroSci"></div>
        <div class="hero-meta" id="heroMeta"></div>
        <div class="hero-badges" id="heroBadges"></div>
        <audio id="heroAudio" controls preload="none" style="display:none; width:100%; margin-top:10px;"></audio>
        <div class="hero-actions">
          <a id="heroDetailLink" href="?view=Species" class="ui-button-link">All species &rarr;</a>
          <a id="heroReviewLink" href="?view=Review" class="ui-button-link" style="display:none;">Review <span id="reviewWorthyCount">0</span> uncertain visits &rarr;</a>
        </div>
      </div>
    </section>

    <section class="now-kpis" aria-label="Today's totals">
      <div class="ui-card kpi-mini"><div class="kpi-mini-value" id="kpiDetections"><?php echo (int)$summary['todaycount']; ?></div><div class="kpi-mini-label">Detections today</div></div>
      <div class="ui-card kpi-mini"><div class="kpi-mini-value" id="kpiSpecies"><?php echo (int)$summary['speciestally']; ?></div><div class="kpi-mini-label">Species today</div></div>
      <div class="ui-card kpi-mini" title="<?php echo h($visit_explainer); ?>"><div class="kpi-mini-value" id="kpiVisits"><?php echo $visits_today; ?></div><div class="kpi-mini-label">Visits today <span class="info-badge">i</span></div></div>
      <div class="ui-card kpi-mini"><div class="kpi-mini-value" id="kpiNew"><?php echo (int)$summary['newspeciestally']; ?></div><div class="kpi-mini-label">New species</div></div>
      <div class="kpi-lifetime">Lifetime: <strong><?php echo number_format((int)$summary['totalcount']); ?></strong> detections &middot; <strong><?php echo (int)$summary['totalspeciestally']; ?></strong> species</div>
    </section>
  </div>

  <section class="ui-card now-species" aria-label="Today's species">
    <h3><?php echo nav_icon('bird'); ?> Today's species
      <span class="now-species-hint">detections by hour, with weather</span>
      <span class="view-toggle" role="group" aria-label="Species view style">
        <button type="button" id="speciesViewGrid" class="active" aria-pressed="true">Grid</button><button type="button" id="speciesViewHeatmap" aria-pressed="false">Heatmap</button>
      </span>
    </h3>
    <div class="species-grid" id="todaySpeciesContainer"><div class="visit-empty">Loading&hellip;</div></div>
  </section>
</div>

<script src="static/dashboard-charts.js?v=<?php echo date('n.d.y', @filemtime('static/dashboard-charts.js')); ?>"></script>
<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var visitExplainer = <?php echo js_arg($visit_explainer); ?>;

  function formatAgo(seconds) {
    if (seconds < 90) return 'just now';
    var mins = Math.round(seconds / 60);
    if (mins < 60) return mins + ' min ago';
    var hours = Math.floor(mins / 60);
    return hours + 'h ' + (mins % 60) + 'm ago';
  }

  function confClass(pct) {
    if (pct >= 90) return 'high';
    if (pct >= 75) return 'med';
    return 'low';
  }

  function weatherEmoji(code, isDay) {
    code = Number(code);
    isDay = Number(isDay) !== 0;
    if (code === 0) return isDay ? '☀️' : '🌙';
    if (code >= 1 && code <= 3) return isDay ? '⛅' : '☁️';
    if (code === 45 || code === 48) return '🌫️';
    if (code >= 51 && code <= 55) return isDay ? '🌦️' : '🌧️';
    if (code >= 61 && code <= 65) return '🌧️';
    if (code >= 71 && code <= 75) return '❄️';
    if (code >= 80 && code <= 82) return isDay ? '🌦️' : '🌧️';
    if (code >= 95) return '⛈️';
    return '☁️';
  }

  function setHeroPhoto(sciName) {
    fetch('api/v1/image/' + encodeURIComponent(sciName), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
      .then(function (j) {
        if (j && j.data && j.data.image_url) {
          document.getElementById('heroPhoto').innerHTML =
            '<img src="' + esc(j.data.image_url) + '" alt="' + esc(sciName) + '">';
        }
      })
      .catch(function () {});
  }

  function renderHero(data) {
    var v = data.latest_visit;
    if (!v) {
      document.getElementById('heroSpecies').textContent = 'No detections yet today';
      document.getElementById('heroMeta').textContent = 'The station is listening.';
      return;
    }
    document.getElementById('heroSpecies').textContent = v.species;
    document.getElementById('heroSci').textContent = v.sci_name;

    var pct = Math.round(v.best_confidence * 100);
    var isActive = typeof v.seconds_ago === 'number' && v.seconds_ago <= (data.gap_seconds || 300);
    var when = isActive
      ? '<span class="hero-active"><span class="live-dot" aria-hidden="true"></span> Active now</span>'
      : esc(formatAgo(v.seconds_ago));
    var weather = '';
    if (data.weather && data.weather.status === 'current') {
      weather = ' &middot; ' + Math.round(data.weather.temp) + '&deg;F ' + esc(data.weather.condition);
    }
    document.getElementById('heroMeta').innerHTML =
      when +
      ' &middot; <span class="feed-badge ' + confClass(pct) + '">' + pct + '%</span>' +
      ' &middot; ' + v.count + ' detection' + (v.count === 1 ? '' : 's') + ' this visit' +
      ' <span class="info-badge" title="' + esc(visitExplainer) + '">i</span>' + weather;

    var badges = [];
    if (v.is_new_lifetime) {
      badges.push('<span class="hero-badge new">NEW SPECIES</span>');
    } else if (v.visits_last_7_days <= 2) {
      badges.push('<span class="hero-badge rare">UNCOMMON VISITOR</span>');
    } else {
      badges.push('<span class="hero-badge regular">' + v.visits_last_7_days + ' visits this week</span>');
    }
    document.getElementById('heroBadges').innerHTML = badges.join(' ');

    if (v.clip_path) {
      var audio = document.getElementById('heroAudio');
      var src = '/By_Date/' + v.clip_path.split('/').map(encodeURIComponent).join('/');
      if (audio.getAttribute('data-src') !== src) {
        audio.setAttribute('data-src', src);
        audio.src = src;
        audio.style.display = '';
        audio.onerror = function () { audio.style.display = 'none'; };
      }
    }
    setHeroPhoto(v.sci_name);
  }

  function renderKpis(data) {
    document.getElementById('kpiDetections').textContent = data.today.detections;
    document.getElementById('kpiSpecies').textContent = data.today.species;
    document.getElementById('kpiVisits').textContent = data.today.visits;
    document.getElementById('kpiNew').textContent = data.today.new_species;
    if (data.review_worthy > 0) {
      document.getElementById('reviewWorthyCount').textContent = data.review_worthy;
      document.getElementById('heroReviewLink').style.display = '';
    }
  }

  function refreshNow() {
    fetch('api/v1/dashboard/now?_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('now failed'); return r.json(); })
      .then(function (data) {
        renderHero(data);
        renderKpis(data);
      })
      .catch(function () {});
  }

  function hourLabel(h) {
    if (h === 0) return '12a';
    if (h < 12) return h + 'a';
    if (h === 12) return '12p';
    return (h - 12) + 'p';
  }

  function renderHourChart(hourly, weather, currentHour) {
    var max = 1;
    var counts = [];
    for (var h = 0; h < 24; h++) {
      var c = hourly && hourly[h] ? hourly[h] : 0;
      counts.push(c);
      if (c > max) max = c;
    }
    var bars = counts.map(function (c, h) {
      var cls = [];
      if (c > 0) cls.push('on');
      if (currentHour != null && h > currentHour) cls.push('future');
      return '<i' + (cls.length ? ' class="' + cls.join(' ') + '"' : '') +
        ' style="height:' + Math.max(6, Math.round((c / max) * 100)) + '%"' +
        ' title="' + hourLabel(h) + ' — ' + c + ' detection' + (c === 1 ? '' : 's') + '"></i>';
    }).join('');

    var axis = '';
    for (var ah = 0; ah < 24; ah += 3) {
      var w = weather ? weather[ah] : null;
      axis += '<div class="axis-col">' +
        '<span class="axis-time">' + hourLabel(ah) + '</span>' +
        (w ? '<span class="axis-weather" aria-hidden="true">' + weatherEmoji(w.code, w.is_day) + '</span>' +
             '<span class="axis-temp">' + Math.round(w.temp) + '&deg;</span>' : '') +
        '</div>';
    }
    return '<div class="spark">' + bars + '</div><div class="spark-axis">' + axis + '</div>';
  }

  var speciesData = null;
  var speciesView = localStorage.getItem('birdnet-species-view') === 'heatmap' ? 'heatmap' : 'grid';

  function renderSpeciesGridCards(data) {
    return (data.species || []).map(function (s) {
      var photo = s.image
        ? '<img loading="lazy" src="' + esc(s.image) + '" alt="">'
        : '<span class="species-card-noimg" aria-hidden="true">&#119067;</span>';
      return '<div class="species-card-mini">' +
        '<div class="species-card-photo">' + photo + '</div>' +
        '<div class="species-card-head">' +
          '<span class="species-card-name" title="' + esc(s.name) + '">' + esc(s.name) + '</span>' +
          '<span class="species-card-stats">' + s.count + ' detections</span>' +
        '</div>' +
        renderHourChart(data.hourly ? data.hourly[s.name] : null, data.weather, data.currentHour) +
        '</div>';
    }).join('');
  }

  function renderSpecies() {
    var box = document.getElementById('todaySpeciesContainer');
    if (speciesView === 'heatmap') {
      // The heatmap is the original Overview canvas renderer
      // (static/dashboard-charts.js): bird thumbnails, hover image previews,
      // rounded cells, weather header, retina scaling. We just give it its
      // expected elements and let it fetch and draw.
      box.className = 'heatmap-embed';
      if (!document.getElementById('hourlyHeatmap')) {
        box.innerHTML = '<div id="heatmapError" style="margin-bottom:8px;"></div>' +
          '<div class="chart-canvas-wrapper ui-chart-scroll" style="max-width:100%;"><canvas id="hourlyHeatmap"></canvas></div>';
      }
      if (window.DashboardCharts) {
        DashboardCharts.refresh();
      }
      return;
    }
    if (!speciesData) {
      return;
    }
    box.className = 'species-grid';
    if (!speciesData.species || speciesData.species.length === 0) {
      box.innerHTML = '<div class="visit-empty">No species yet today.</div>';
      return;
    }
    box.innerHTML = renderSpeciesGridCards(speciesData);
  }

  function setSpeciesView(mode) {
    speciesView = mode;
    localStorage.setItem('birdnet-species-view', mode);
    var gridBtn = document.getElementById('speciesViewGrid');
    var heatBtn = document.getElementById('speciesViewHeatmap');
    gridBtn.classList.toggle('active', mode === 'grid');
    gridBtn.setAttribute('aria-pressed', mode === 'grid' ? 'true' : 'false');
    heatBtn.classList.toggle('active', mode === 'heatmap');
    heatBtn.setAttribute('aria-pressed', mode === 'heatmap' ? 'true' : 'false');
    if (mode === 'grid' && !speciesData) {
      refreshSpeciesGrid();
      return;
    }
    renderSpecies();
  }

  document.getElementById('speciesViewGrid').addEventListener('click', function () { setSpeciesView('grid'); });
  document.getElementById('speciesViewHeatmap').addEventListener('click', function () { setSpeciesView('heatmap'); });
  if (speciesView === 'heatmap') {
    setSpeciesView('heatmap');
  }

  function refreshSpeciesGrid() {
    if (speciesView === 'heatmap') {
      // The heatmap renderer fetches its own data and redraws.
      if (window.DashboardCharts && document.getElementById('hourlyHeatmap')) {
        DashboardCharts.refresh();
      }
      return;
    }
    fetch('overview.php?ajax_chart_data=true&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('grid failed'); return r.json(); })
      .then(function (data) {
        speciesData = data;
        renderSpecies();
      })
      .catch(function () {
        document.getElementById('todaySpeciesContainer').innerHTML = '<div class="visit-empty">Species data unavailable.</div>';
      });
  }

  refreshNow();
  refreshSpeciesGrid();
  setInterval(refreshNow, 30000);
  setInterval(refreshSpeciesGrid, 120000);
})();
</script>
