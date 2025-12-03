<?php

$filePath = __DIR__ . '/data.json';

if (!file_exists($filePath)) {
    file_put_contents($filePath, json_encode([], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function read_data($path){
    $raw = @file_get_contents($path);
    $arr = @json_decode($raw, true);
    return is_array($arr) ? $arr : [];
}
function write_data($path, $data){
    file_put_contents($path, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
function clean($s){
    return trim(htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE));
}
function mapMoodToLevel($mood){
    $m = strtolower(trim($mood));
    if ($m === 'senang') return 3;
    if ($m === 'sedih') return 1;
    return 2;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = read_data($filePath);

    $tanggal = preg_match('/^\d{4}-\d{2}-\d{2}$/', ($_POST['tanggal'] ?? ''))
                ? $_POST['tanggal'] : date('Y-m-d');

    $mood = clean($_POST['mood'] ?? 'Biasa');
    $catatan = clean($_POST['catatan'] ?? '');
    $air = 0;

    if (isset($_POST['airminum']) && is_array($_POST['airminum'])) {
        foreach ($_POST['airminum'] as $v) if (is_numeric($v)) $air++;
    }

    $entry = [
        'tanggal' => $tanggal,
        'mood' => $mood,
        'catatan' => nl2br($catatan),
        'airminum' => (int)$air,
        'tugas' => nl2br(clean($_POST['tugas'] ?? '')),
        'pencapaian' => nl2br(clean($_POST['pencapaian'] ?? '')),
        'catatanAkhir' => nl2br(clean($_POST['catatanAkhir'] ?? '')),
    ];

    $data[] = $entry;
    write_data($filePath, $data);

    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (isset($_GET['undo'])) {
    $data = read_data($filePath);
    array_pop($data);
    write_data($filePath, $data);
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

if (isset($_GET['hapus'])) {
    $idx = (int)$_GET['hapus'];
    $data = read_data($filePath);
    if (isset($data[$idx])) {
        array_splice($data, $idx, 1);
        write_data($filePath, $data);
    }
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$data = read_data($filePath);

$chartLabels = array_map(fn($d)=>$d['tanggal'], $data);
$chartData   = array_map(fn($d)=>mapMoodToLevel($d['mood']), $data);

$avgAir = 0;
if (count($data) > 0) {
    $sum = 0;
    foreach ($data as $d) $sum += (int)($d['airminum'] ?? 0);
    $avgAir = $sum / count($data);
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mood Journal</title>
<link rel="stylesheet" href="style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>

<body data-theme="<?= htmlspecialchars($_GET['theme'] ?? 'soft-pastel') ?>">
<div class="container">

<header class="header">
    <div class="title">
        <div class="logo">⭐</div>
        <h1>Mood Journal</h1>
    </div>

    <div class="theme-switch">
    </div>
</header>

<form method="POST" class="section">
    <label>Tanggal:</label>
    <input type="date" name="tanggal" value="<?= date('Y-m-d') ?>" required>

    <label>Mood:</label>
    <select name="mood" required>
        <option value="Senang">😊 Senang</option>
        <option value="Biasa" selected>😐 Biasa</option>
        <option value="Sedih">😢 Sedih</option>
    </select>

    <label>Catatan Harian:</label>
    <textarea name="catatan" rows="4"></textarea>

    <label>Air Minum (8 Gelas):</label>
    <div class="air-box" id="airBox">
        <?php for($i=1;$i<=8;$i++): ?>
            <label class="cup-wrapper">
                <input type="checkbox" name="airminum[]" value="<?= $i ?>">
                <div class="cup realistic"></div>
            </label>
        <?php endfor; ?>
    </div>

    <label>Tugas:</label>
    <textarea name="tugas" rows="3"></textarea>

    <label>Pencapaian:</label>
    <textarea name="pencapaian" rows="3"></textarea>

    <label>Catatan Tambahan:</label>
    <textarea name="catatanAkhir" rows="3"></textarea>

    <div class="button-row">
        <button class="primary" type="submit">Simpan</button>
        <a href="?undo=1" onclick="return confirm('Undo entri terakhir?')">
            <button class="ghost" type="button">Undo Terakhir</button>
        </a>
    </div>
</form>

<h3>📊 Grafik Mood</h3>
<div class="canvas-wrap">
    <canvas id="moodChart"></canvas>
</div>

<h3>📚 Riwayat Jurnal</h3>
<table>
    <tr>
        <th>Tanggal</th>
        <th>Mood</th>
        <th>Catatan</th>
        <th>Air</th>
        <th>Tugas</th>
        <th>Pencapaian</th>
        <th>Catatan Akhir</th>
        <th>Aksi</th>
    </tr>

    <?php foreach($data as $i => $d): ?>
    <tr>
        <td><?= htmlspecialchars($d['tanggal']) ?></td>
        <td><?= htmlspecialchars($d['mood']) ?></td>
        <td><?= $d['catatan'] ?></td>
        <td><?= (int)($d['airminum'] ?? 0) ?>/8</td>
        <td><?= $d['tugas'] ?></td>
        <td><?= $d['pencapaian'] ?></td>
        <td><?= $d['catatanAkhir'] ?></td>
        <td>
            <a href="?hapus=<?= $i ?>" onclick="return confirm('Hapus riwayat ini?')">
                <button class="hapus-btn" type="button">Hapus</button>
            </a>
        </td>
    </tr>
    <?php endforeach; ?>
</table>

<div class="small-muted">
    Rata-rata air minum: <?= round($avgAir, 2) ?> gelas
</div>

</div>

<script>

const chartLabels = <?= json_encode($chartLabels) ?>;
const chartData   = <?= json_encode($chartData) ?>;

const ctx = document.getElementById("moodChart").getContext("2d");
let moodChart = new Chart(ctx, {
    type: "line",
    data: {
        labels: chartLabels,
        datasets: [{
            label: "Mood Level",
            data: chartData,
            borderWidth: 3,
            borderColor: "#b8006b",
            pointBackgroundColor: "#ff7aa2",
            pointRadius: 4,
            tension: 0.25
        }]
    },
    options: {
        scales:{
            y:{min:1,max:3,stepSize:1}
        }
    }
});

const themeSelect = document.getElementById("themeSelect");
const applyBtn = document.getElementById("applyThemeBtn");
const bodyEl = document.body;
const airBox = document.getElementById("airBox");

function applyTheme(theme){
    bodyEl.dataset.theme = theme;
    const styles = getComputedStyle(bodyEl);

    const border = styles.getPropertyValue("--chart-color").trim();
    const accent = styles.getPropertyValue("--accent").trim();

    moodChart.data.datasets[0].borderColor = border;
    moodChart.data.datasets[0].pointBackgroundColor = accent;
    moodChart.update();

    localStorage.setItem("chosenTheme", theme);
}

applyBtn.addEventListener("click", ()=> applyTheme(themeSelect.value));

document.addEventListener("DOMContentLoaded", ()=>{
    const saved = localStorage.getItem("chosenTheme") || "soft-pastel";
    themeSelect.value = saved;
    applyTheme(saved);
});
</script>

</body>
</html>
