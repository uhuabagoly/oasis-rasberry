<?php 
// index.php - OASYS dashboard (teljes fájl)
// Mentés: index.php, helyezd mellé a data.txt-t vagy módosítsd a $filename útvonalát.

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Europe/Budapest');

$filename = __DIR__ . "/data.txt";

/* ------- HELPER: parse egy sorból assoc tömböt ------- */
function parse_line_to_assoc(string $line) : ?array {
    $line = trim($line);
    if ($line === '') return null;
    $cols = preg_split('/\s+/', $line);

    $item = [
        'raw' => $line,
        'lastDate' => $cols[0] ?? '',
        'lastTimeRaw' => $cols[1] ?? '',
        'moisture' => isset($cols[3]) && is_numeric($cols[3]) ? (float)$cols[3] : null,
        'water' => isset($cols[4]) && is_numeric($cols[4]) ? (float)$cols[4] : null,
        'light' => isset($cols[5]) && is_numeric($cols[5]) ? (float)$cols[5] : null,
        'pressure' => isset($cols[9]) && is_numeric($cols[9]) ? (float)$cols[9] : null,
        'temp' => isset($cols[10]) && is_numeric($cols[10]) ? (float)$cols[10] : null,
        'humidity' => isset($cols[11]) && is_numeric($cols[11]) ? (float)$cols[11] : null,
    ];

    $lt = $item['lastTimeRaw'] ?? '';
    if (strlen($lt) === 6 && ctype_digit($lt)) {
        $item['lastTime'] = substr($lt,0,2) . ':' . substr($lt,2,2) . ':' . substr($lt,4,2);
    } else {
        $item['lastTime'] = $lt;
    }

    $item['timestamp'] = ($item['lastDate'] !== '' && $item['lastTime'] !== '') ? ($item['lastDate'] . ' ' . $item['lastTime']) : null;
    return $item;
}

/* ------- read latest (memóriatakarékos) ------- */
function read_latest_data(string $filename) : array {
    $fallback = [
        "lastDate" => "",
        "lastTime" => "",
        "moisture" => 0.0,
        "water"    => 0.0,
        "light"    => 0.0,
        "temp"     => 0.0,
        "humidity" => 0.0,
        "pressure" => null,
        "raw"      => "",
        "ok"       => false
    ];
    if (!file_exists($filename) || !is_readable($filename)) return $fallback;

    try {
        $f = new SplFileObject($filename, 'r');
        $f->seek(PHP_INT_MAX);
        $lastIndex = $f->key();

        for ($i = $lastIndex; $i >= 0; $i--) {
            $f->seek($i);
            $ln = trim($f->current());
            if ($ln !== '') {
                $last = parse_line_to_assoc($ln);
                if ($last) {
                    $out = array_merge($fallback, $last);
                    $out['ok'] = true;
                    return $out;
                }
            }
        }
    } catch (Exception $e) {
        // fallback továbbra is
    }
    return $fallback;
}

/* ------- read history (last N) ------- */
function read_history(string $filename, int $lines = 100) : array {
    $out = [];
    if (!file_exists($filename) || !is_readable($filename)) return $out;
    try {
        $f = new SplFileObject($filename, 'r');
        $f->seek(PHP_INT_MAX);
        $lastIndex = $f->key();
        $start = max(0, $lastIndex - $lines + 1);
        $f->seek($start);
        while (!$f->eof()) {
            $ln = $f->current();
            $p = parse_line_to_assoc($ln);
            if ($p) $out[] = $p;
            $f->next();
        }
    } catch (Exception $e) {
        // üres lista visszaadása
    }
    return $out;
}

/* ------- count non-empty lines (max visszanézhető) ------- */
function count_nonempty_lines(string $filename) : int {
    if (!file_exists($filename) || !is_readable($filename)) return 0;
    $count = 0;
    try {
        $f = new SplFileObject($filename, 'r');
        while (!$f->eof()) {
            $ln = trim($f->current());
            if ($ln !== '') $count++;
            $f->next();
        }
    } catch (Exception $e) {
        return 0;
    }
    return $count;
}

/* ------- API endpoints ------- */
if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, must-revalidate');

    $api = $_GET['api'];
    if ($api === '1') {
        echo json_encode(read_latest_data($filename), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($api === 'history') {
        $lines = isset($_GET['lines']) ? max(1, (int)$_GET['lines']) : 120;
        echo json_encode(read_history($filename, $lines), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($api === 'tail') {
        $lines = isset($_GET['lines']) ? max(1, (int)$_GET['lines']) : 50;
        $hist = read_history($filename, $lines);
        $raws = array_map(function($r){ return $r['raw']; }, $hist);
        echo json_encode($raws, JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($api === 'count') {
        // visszaadjuk a nem üres sorok számát (max visszanézés)
        echo json_encode(['total' => count_nonempty_lines($filename)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ------- Page render ------- */
$initial = read_latest_data($filename);
$initial_json = json_encode($initial, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$availableLines = count_nonempty_lines($filename);
?>
<!doctype html>
<html lang="hu">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>OASYS — Dashboard</title>

  <!-- Bootstrap + Chart.js -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>

  <style>
    :root{
      --color-moisture:#1e90ff; --color-water:#4caf50; --color-humidity:#00bcd4;
      --color-temp:#ff7043; --color-light:#fbc02d; --bg:#f5fbf9; --muted:#6c757d;
    }
    [data-theme="dark"]{ --bg:#07121a; --muted:#9aa6b2; color-scheme:dark; }

    body{ margin:0; min-height:100vh; background:var(--bg); font-family:Inter,system-ui,-apple-system,'Segoe UI',Roboto,Arial; padding:20px; }
    .container-main{ max-width:1200px; margin:0 auto; }
    h1.title { text-align:center; margin-bottom:18px; font-size:1.4rem; }

    /* KPI row - centered */
    .kpi-row{ display:flex; gap:18px; justify-content:center; align-items:stretch; flex-wrap:wrap; margin-bottom:18px; }
    .kpi-card{ width:220px; background:#fff; border-radius:14px; padding:14px; text-align:center; display:flex; flex-direction:column; align-items:center; gap:8px; box-shadow:0 10px 30px rgba(0,0,0,0.06); }
    .kpi-big{ font-weight:700; font-size:1.6rem; }
    .kpi-sub{ color:var(--muted); font-size:0.9rem; }

    .mini-doughnut{ width:110px; height:110px; position:relative; display:flex; align-items:center; justify-content:center; }
    .mini-doughnut .center-text{ position:absolute; font-weight:700; font-size:1rem; }

    .charts-grid{ display:grid; grid-template-columns: 1fr 1fr; gap:18px; align-items:start; margin-bottom:20px; }
    .card-box{ background:#fff; border-radius:12px; padding:14px; box-shadow:0 10px 30px rgba(0,0,0,0.06); }

    #overlay{ position:fixed; inset:0; background:rgba(0,0,0,0.25); opacity:0; pointer-events:none; transition:opacity .25s; z-index:1000; }
    #overlay.show{ opacity:1; pointer-events:auto; }
    #dashboardPanel{
      position:fixed; top:0; right:-100vw; height:100vh; width:70vw; max-width:1400px; background:#fff; box-shadow:-8px 0 40px rgba(0,0,0,0.2); transition:right .28s ease; z-index:1100; display:flex; flex-direction:column; padding:18px; overflow:hidden;
    }
    #dashboardPanel.open{ right:0; }
    @media (max-width:900px){ #dashboardPanel{ width:100vw; } }

    #toggleDash{
      position:fixed; right:18px; bottom:18px; z-index:1200; background:#0d6efd; color:#fff; border-radius:12px; padding:14px 18px; font-weight:700; box-shadow:0 8px 30px rgba(13,110,253,0.24); cursor:pointer; display:flex; gap:8px; align-items:center;
    }

    .panel-top { display:flex; gap:12px; align-items:center; justify-content:space-between; }
    .panel-controls { display:flex; gap:8px; align-items:center; margin-top:12px; }
    .panel-charts { display:grid; grid-template-columns: 1fr; gap:12px; margin-top:12px; overflow:auto; padding-bottom:12px; }
    .log-box{ font-family:monospace; white-space:pre-wrap; word-break:break-word; background:#f8f9fa; border-radius:8px; padding:10px; max-height:220px; overflow:auto; border:1px solid #eee; }
    footer.small-muted { text-align:center; color:var(--muted); margin-top:12px; font-size:0.85rem; }

    canvas { display:block; }
    #summaryChart { height:220px !important; }
    #chartMoist, #chartWater, #chartTemp, #detailChart { width:100%; height:180px !important; }

    /* PANEL: engedjük, hogy belső rész görgethető legyen (különösen vízszintesen) */
#dashboardPanel{
  position:fixed; top:0; right:-100vw; height:100vh; width:70vw; max-width:1400px; background:#fff; box-shadow:-8px 0 40px rgba(0,0,0,0.2); transition:right .28s ease; z-index:1100; display:flex; flex-direction:column; padding:18px;
  /* a panel maga marad rejtett a jobb oldalra, de belső elemei görgethetnek */
  overflow: hidden;
}

/* panel-charts belső görgetése — engedi a vízszintes scrollt */
.panel-charts {
  display:grid;
  grid-template-columns: 1fr;
  gap:12px;
  margin-top:12px;
  overflow-x: auto;   /* vízszintes görgetés engedélyezése */
  overflow-y: auto;
  padding-bottom:12px;
  -webkit-overflow-scrolling: touch;
}

/* részletes chart base méret — JS fogja felülírni a szélességet */
#detailChart { 
  height: 420px !important; /* nagyobb magasság, hogy kényelmes legyen a részletes nézet */
  display: block;
}

/* canvas alap: ne legyen inline-block, hogy scroll működjön megbízhatóan */
.panel-charts canvas { display: block; }

  </style>
</head>
<body data-theme="light">
  <div class="container-main">
    <h1 class="title">OASYS — Valós idejű növénykövetés</h1>

    <!-- KPI row -->
    <section class="kpi-row" aria-label="KPI-k">
      <div class="kpi-card">
        <div class="mini-doughnut"><canvas id="doughMoist" width="110" height="110"></canvas></div>
        <div class="kpi-big" id="kpiMoist"><?php echo htmlspecialchars($initial['moisture']); ?>%</div>
        <div class="kpi-sub">Talajnedvesség</div>
      </div>

      <div class="kpi-card">
        <div class="mini-doughnut"><canvas id="doughWater" width="110" height="110"></canvas></div>
        <div class="kpi-big" id="kpiWater"><?php echo htmlspecialchars($initial['water']); ?>%</div>
        <div class="kpi-sub">Vízszint</div>
      </div>

      <div class="kpi-card">
        <div class="mini-doughnut"><canvas id="doughTemp" width="110" height="110"></canvas></div>
        <div class="kpi-big" id="kpiTemp"><?php echo htmlspecialchars($initial['temp']); ?>°C</div>
        <div class="kpi-sub">Hőmérséklet</div>
      </div>

      <div class="kpi-card">
        <div class="mini-doughnut"><canvas id="doughLight" width="110" height="110"></canvas></div>
        <div class="kpi-big" id="kpiLight"><?php echo htmlspecialchars($initial['light']); ?></div>
        <div class="kpi-sub">Fény (lux)</div>
      </div>
    </section>

    <!-- charts -->
    <section class="charts-grid" aria-label="Main charts">
      <div class="card-box">
        <h5>Összefoglaló</h5>
        <canvas id="summaryChart"></canvas>
      </div>

      <div class="card-box">
        <h5>Státusz</h5>
        <div class="small-muted">Metaadatok</div>
        <ul class="list-unstyled mt-3">
          <li>Nyomás: <strong id="txtPres"><?php echo htmlspecialchars($initial['pressure'] ?? 'N/A'); ?> hPa</strong></li>
          <li>Páratartalom: <strong id="txtHum"><?php echo htmlspecialchars($initial['humidity']); ?> %</strong></li>
          <li>Raw sor: <small id="rawLine"><?php echo htmlspecialchars($initial['raw']); ?></small></li>
        </ul>
      </div>
    </section>

    <footer class="small-muted">Adatok utoljára frissítve: <span id="statusWhen"><?php echo date('Y-m-d H:i:s'); ?></span></footer>
  </div>

  <!-- overlay and dashboard panel -->
  <div id="overlay"></div>

  <div id="dashboardPanel" role="dialog" aria-hidden="true" aria-label="Részletes dashboard panel">
    <div class="panel-top">
      <div>
        <h4 style="margin:0">Részletes Dashboard</h4>
        <div class="small-muted">Azonnal látható vonaldiagramok (utolsó N mérés)</div>
      </div>
      <div>
        <button id="closePanel" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x"></i> Bezár</button>
      </div>
    </div>

    <div class="panel-controls" style="margin-top:12px;">
      <select id="metricSelect" class="form-select form-select-sm" style="width:220px;">
        <option value="moisture" selected>Talajnedvesség</option>
        <option value="water">Vízszint</option>
        <option value="temp">Hőmérséklet</option>
        <option value="light">Fény (lux)</option>
      </select>

      <!-- csak vonaldiagram lehetőség: nincs scelta -->
      <input id="historyLen" type="number" class="form-control form-control-sm" style="width:96px;" value="120" min="1" max="2000" />
      <button id="btnLoadHistory" class="btn btn-sm btn-primary">Betölt</button>

      <!-- Új információs mező: jelenleg visszanézhető sorok száma -->
      <div style="margin-left:12px; display:inline-block;">
        <div id="historyInfo" class="small-muted">Elérhető visszanézés: <span id="historyCount"><?php echo $availableLines; ?></span> sor</div>
      </div>
    </div>

    <div class="panel-charts" style="margin-top:12px;">
      <canvas id="detailChart"></canvas>

      <h5 style="margin-top:12px">Log (utolsó sorok)</h5>
      <div id="panelLog" class="log-box"></div>
    </div>
  </div>

  <!-- prominent toggle -->
  <div id="toggleDash" title="Részletes nézet megnyitása">
    <i class="bi bi-columns-gap" style="font-size:1.15rem"></i>
    <span style="font-weight:700;margin-left:8px">Részletes nézet</span>
  </div>

<script>
/* ====== initial data ====== */
const initialData = <?php echo $initial_json; ?>;
let lastRawLine = initialData.raw || "";
let maxHistory = <?php echo (int)$availableLines; ?>; // aktuális legalább az oldal betöltésekor

/* ====== helpers ====== */
function getMetricColor(m) {
  return {
    moisture:'#1e90ff',
    water:'#4caf50',
    temp:'#ff7043',
    light:'#fbc02d',
    humidity:'#00bcd4'
  }[m]||'#888';
}
function clampInt(v, min, max) {
  v = parseInt(v,10);
  if (isNaN(v)) return min;
  return Math.max(min, Math.min(max, v));
}

/* ====== mini doughnuts ====== */
function createMiniDough(id, value, max, color){
  return new Chart(document.getElementById(id), {
    type:'doughnut',
    data:{datasets:[{ data:[value, Math.max(0,max-value)], backgroundColor:[color,'#eee'], borderWidth:0 }]},
    options:{ cutout:'70%', plugins:{legend:{display:false}} }
  });
}
const miniMoist = createMiniDough('doughMoist', initialData.moisture||0, 100, getMetricColor('moisture'));
const miniWater = createMiniDough('doughWater', initialData.water||0, 100, getMetricColor('water'));
const miniTemp  = createMiniDough('doughTemp', initialData.temp||0, 50,  getMetricColor('temp'));
const miniLight = createMiniDough('doughLight', initialData.light||0, 2000,getMetricColor('light'));

/* ====== summary chart ====== */
const summaryChart = new Chart(document.getElementById('summaryChart'), {
  type:'bar',
  data:{ labels:['Talaj','Víz','Pára'],
    datasets:[{ data:[initialData.moisture||0, initialData.water||0, initialData.humidity||0],
    backgroundColor:[getMetricColor('moisture'), getMetricColor('water'), getMetricColor('humidity')] }]},
  options:{ responsive:true, plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
});

/* ====== KPI update ====== */
function updateKPI(d) {
  document.getElementById('kpiMoist').textContent = (d.moisture ?? 0).toFixed(1)+'%';
  document.getElementById('kpiWater').textContent = (d.water ?? 0).toFixed(1)+'%';
  document.getElementById('kpiTemp').textContent = (d.temp ?? 0).toFixed(1)+'°C';
  document.getElementById('kpiLight').textContent = (d.light ?? 0);
  document.getElementById('txtHum').textContent = (d.humidity ?? 0);
  document.getElementById('txtPres').textContent = (d.pressure ?? 'N/A');
  document.getElementById('rawLine').textContent = (d.raw ?? '');
  document.getElementById('statusWhen').textContent = new Date().toLocaleString();

  try {
    miniMoist.data.datasets[0].data=[d.moisture||0, Math.max(0,100-(d.moisture||0))]; miniMoist.update();
    miniWater.data.datasets[0].data=[d.water||0, Math.max(0,100-(d.water||0))]; miniWater.update();
    miniTemp.data.datasets[0].data =[d.temp||0, Math.max(0,50-(d.temp||0))]; miniTemp.update();
    miniLight.data.datasets[0].data=[d.light||0, Math.max(0,2000-(d.light||0))]; miniLight.update();

    summaryChart.data.datasets[0].data=[d.moisture||0, d.water||0, d.humidity||0];
    summaryChart.update();
  } catch(e) {
    console.warn('chart update error', e);
  }
}

/* ====== Fetch latest (polling fallback) ====== */
async function fetchLatest() {
  try {
    const r = await fetch('?api=1', { cache: 'no-store' });
    if (!r.ok) throw new Error('Network ' + r.status);
    const j = await r.json();
    if (j.raw && j.raw !== lastRawLine) {
      lastRawLine = j.raw;
      updateKPI(j);
      // ha panel nyitva van, frissítsük a részletes nézetet (ez frissíti a count-ot is)
      if (panel.classList.contains('open')) loadPanelData();
      else {
        // frissítsük a helyi maxHistory-t is (opcionális)
        // lekérhetjük a count endpointot, de itt hagyjuk, hogy loadPanelData kezelje, amikor szükséges
      }
    }
  } catch (err) {
    console.error('fetchLatest error', err);
  }
}

/* ====== PANEL controls ====== */
const panel = document.getElementById('dashboardPanel');
const overlay = document.getElementById('overlay');
const toggle = document.getElementById('toggleDash');
const closeBtn = document.getElementById('closePanel');
const metricSelect = document.getElementById('metricSelect');
const historyLenInput = document.getElementById('historyLen');
const btnLoadHistory = document.getElementById('btnLoadHistory');
let detailChart = null;

function openPanel() { panel.classList.add('open'); overlay.classList.add('show'); loadPanelData(); }
function closePanel(){ panel.classList.remove('open'); overlay.classList.remove('show'); }

toggle.addEventListener('click', openPanel);
overlay.addEventListener('click', closePanel);
closeBtn.addEventListener('click', closePanel);
btnLoadHistory.addEventListener('click', () => loadPanelData());
metricSelect.addEventListener('change', () => loadPanelData());
historyLenInput.addEventListener('keydown', (e) => { if (e.key === 'Enter') loadPanelData(); });

/* ====== Load panel data (frissített count + history + log) ====== */
async function loadPanelData() {
  try {
    // 1) lekérjük a friss max számat
    const cr = await fetch('?api=count', { cache: 'no-store' });
    const cj = await cr.json();
    maxHistory = parseInt(cj.total || 0, 10);
    document.getElementById('historyCount').textContent = maxHistory;

    // 2) beolvasott kért érték
    let requested = clampInt(historyLenInput.value || 120, 1, 1000000);

    // ha nagyobb, visszaállítjuk a maximumra és jelzést adunk
    if (requested > maxHistory) {
      requested = maxHistory;
      historyLenInput.value = requested;
      // rövid üzenet a felhasználónak
      const info = document.getElementById('historyInfo');
      const prev = info.textContent;
      info.textContent = `Kérés meghaladja a maximális visszanézhetőséget — betöltve: ${requested} sor (max)`;
      setTimeout(()=> { info.innerHTML = `Elérhető visszanézés: <span id="historyCount">${maxHistory}</span> sor`; }, 4000);
    }

    // 3) lekérjük a history-t és a logot
    const r = await fetch('?api=history&lines=' + requested, { cache: 'no-store' });
    if (!r.ok) throw new Error('history fetch ' + r.status);
    const hist = await r.json();
    renderDetailChart(hist);

    const r2 = await fetch('?api=tail&lines=50', { cache: 'no-store' });
    if (r2.ok) {
      const rawArr = await r2.json();
      document.getElementById('panelLog').textContent = rawArr.join("\n");
    } else {
      document.getElementById('panelLog').textContent = 'Log nem elérhető';
    }
  } catch (err) {
    console.error('loadPanelData error', err);
    document.getElementById('panelLog').textContent = 'Hiba a történet betöltésekor';
  }
}

/* ====== Render detail chart - wide canvas ====== */
function renderDetailChart(historyArr) {
  if (!historyArr || historyArr.length === 0) {
    // clear chart area
    if (detailChart) { detailChart.destroy(); detailChart = null; }
    return;
  }
  const metric = metricSelect.value;
  const labels = historyArr.map(h => (h.lastDate || '') + ' ' + (h.lastTime || ''));
  const values = historyArr.map(h => (h[metric] !== undefined && h[metric] !== null) ? h[metric] : 0);

  // dynamic width to avoid compression
  const pixelsPerPoint = 40;
  const minWidth = 700;
  const computedWidth = Math.max(minWidth, labels.length * pixelsPerPoint);

  const canvas = document.getElementById('detailChart');
  canvas.style.width = computedWidth + 'px';
  canvas.style.height = '420px';

  if (detailChart) detailChart.destroy();
  const ctx = canvas.getContext('2d');
  detailChart = new Chart(ctx, {
    type: 'line',
    data: { labels: labels, datasets: [{ label: metric, data: values, borderColor: getMetricColor(metric), backgroundColor: getMetricColor(metric), fill:false, tension:0.25, pointRadius:2 }] },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      scales: { x: { display:true, ticks: { maxRotation:45, autoSkip:true, maxTicksLimit: Math.floor(computedWidth / 80) } }, y: { beginAtZero:true } },
      plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } }
    }
  });

  // auto-scroll to end
  const scrollParent = document.querySelector('.panel-charts');
  if (scrollParent) {
    setTimeout(()=> { scrollParent.scrollLeft = canvas.offsetWidth - scrollParent.clientWidth; }, 60);
  }
}

/* ====== Start ====== */
updateKPI(initialData);
// polling every 10s for new data
setInterval(fetchLatest, 10);
</script>
</body>
</html>
