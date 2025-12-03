<?php

$filePath = __DIR__ . '/data.json';

abstract class Storage {
    protected $path;
    public function __construct($path) {
        $this->path = $path;
        if (!file_exists($this->path)) {
            // create empty json array file
            file_put_contents($this->path, json_encode([]));
        }
    }
    abstract public function read();
    abstract public function write($data);
}

class JSONStorage extends Storage {
    private $cache = null;

    public function __construct($path) {
        parent::__construct($path);
        $this->cache = null;
    }

    public function read() {
        $raw = @file_get_contents($this->path);
        $arr = @json_decode($raw, true);
        return is_array($arr) ? $arr : [];
    }

    public function write($data) {
        $this->cache = $data;
        file_put_contents($this->path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    public function all() {
        if ($this->cache === null) $this->cache = $this->read();
        return $this->cache;
    }
}

class Stack {
    private $items = [];
    public function push($v) { $this->items[] = $v; }
    public function pop() { return array_pop($this->items); }
    public function peek() { return end($this->items); }
    public function isEmpty() { return empty($this->items); }
    public function size() { return count($this->items); }
}

class Queue {
    private $items = [];
    public function enqueue($v) { $this->items[] = $v; }
    public function dequeue() { return array_shift($this->items); }
    public function peek() { return reset($this->items); }
    public function isEmpty() { return empty($this->items); }
    public function size() { return count($this->items); }
}


abstract class BaseEntry {
    protected $tanggal;
    protected $mood;
    protected $catatan;
    protected $airminum;
    protected $tugas;
    protected $pencapaian;
    protected $catatanAkhir;

    public function __construct($payload) {
        // defensive normalisation
        $payload['airminum'] = isset($payload['airminum']) ? (int)$payload['airminum'] : 0;
        $payload['tanggal'] = isset($payload['tanggal']) ? $payload['tanggal'] : date('Y-m-d');
        $payload['mood'] = isset($payload['mood']) ? $payload['mood'] : 'Biasa';
        $payload['catatan'] = isset($payload['catatan']) ? $payload['catatan'] : '';
        $payload['tugas'] = isset($payload['tugas']) ? $payload['tugas'] : '';
        $payload['pencapaian'] = isset($payload['pencapaian']) ? $payload['pencapaian'] : '';
        $payload['catatanAkhir'] = isset($payload['catatanAkhir']) ? $payload['catatanAkhir'] : '';

        $this->tanggal = (string)$payload['tanggal'];
        $this->mood = (string)$payload['mood'];
        $this->catatan = (string)$payload['catatan'];
        $this->airminum = (int)$payload['airminum'];
        $this->tugas = (string)$payload['tugas'];
        $this->pencapaian = (string)$payload['pencapaian'];
        $this->catatanAkhir = (string)$payload['catatanAkhir'];
    }

    abstract public function toArray();
}

class StandardEntry extends BaseEntry {
    public function toArray() {
        return [
            'tanggal' => $this->tanggal ?? date('Y-m-d'),
            'mood' => $this->mood ?? 'Biasa',
            'catatan' => nl2br(htmlspecialchars($this->catatan ?? '')),
            'airminum' => isset($this->airminum) ? (int)$this->airminum : 0,
            'tugas' => nl2br(htmlspecialchars($this->tugas ?? '')),
            'pencapaian' => nl2br(htmlspecialchars($this->pencapaian ?? '')),
            'catatanAkhir' => nl2br(htmlspecialchars($this->catatanAkhir ?? ''))
        ];
    }
}

class EmphasizedEntry extends BaseEntry {
    public function toArray() {
        return [
            'tanggal' => $this->tanggal ?? date('Y-m-d'),
            // mood intentionally uppercased for emphasis; mapping is case-insensitive
            'mood' => strtoupper($this->mood ?? 'Biasa'),
            'catatan' => '<strong>' . nl2br(htmlspecialchars($this->catatan ?? '')) . '</strong>',
            'airminum' => isset($this->airminum) ? (int)$this->airminum : 0,
            'tugas' => nl2br(htmlspecialchars($this->tugas ?? '')),
            'pencapaian' => nl2br(htmlspecialchars($this->pencapaian ?? '')),
            'catatanAkhir' => nl2br(htmlspecialchars($this->catatanAkhir ?? ''))
        ];
    }
}


class JournalManager {
    private $storage;
    private $entries;
    private $undoStack;
    private $processQueue;

    public function __construct(JSONStorage $storage) {
        $this->storage = $storage;
        $this->entries = $this->storage->all();

        // Normalise entries to expected shape
        $changed = false;
        foreach ($this->entries as $idx => &$e) {
            if (!is_array($e)) {
                $e = [
                    'tanggal' => date('Y-m-d'),
                    'mood' => 'Biasa',
                    'catatan' => '',
                    'airminum' => 0,
                    'tugas' => '',
                    'pencapaian' => '',
                    'catatanAkhir' => ''
                ];
                $changed = true;
                continue;
            }
            if (!isset($e['tanggal'])) { $e['tanggal'] = date('Y-m-d'); $changed = true; }
            if (!isset($e['mood'])) { $e['mood'] = 'Biasa'; $changed = true; }
            if (!isset($e['catatan'])) { $e['catatan'] = ''; $changed = true; }
            if (!isset($e['airminum'])) { $e['airminum'] = 0; $changed = true; }
            if (!isset($e['tugas'])) { $e['tugas'] = ''; $changed = true; }
            if (!isset($e['pencapaian'])) { $e['pencapaian'] = ''; $changed = true; }
            if (!isset($e['catatanAkhir'])) { $e['catatanAkhir'] = ''; $changed = true; }
        }
        unset($e);

        if ($changed) $this->storage->write($this->entries);

        $this->undoStack = new Stack();
        $this->processQueue = new Queue();
        foreach ($this->entries as $idx => $e) $this->processQueue->enqueue($idx);
    }

    public function addFromPost($post) {
        $tanggal = $post['tanggal'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) $tanggal = date('Y-m-d');

        $air = 0;
        if (isset($post['airminum']) && is_array($post['airminum'])) {
            foreach ($post['airminum'] as $v) if (is_numeric($v)) $air++;
        }

        $payload = [
            'tanggal' => $tanggal,
            'mood' => $post['mood'] ?? 'Biasa',
            'catatan' => $post['catatan'] ?? '',
            'airminum' => $air,
            'tugas' => $post['tugas'] ?? '',
            'pencapaian' => $post['pencapaian'] ?? '',
            'catatanAkhir' => $post['catatanAkhir'] ?? ''
        ];

        $moodLower = strtolower(strip_tags((string)$payload['mood']));
        switch ($moodLower) {
            case 'senang':
            case 'puas':
                $entryObj = new EmphasizedEntry($payload);
                break;
            default:
                $entryObj = new StandardEntry($payload);
        }

        $arr = $entryObj->toArray();
        $this->entries[] = $arr;
        $this->storage->write($this->entries);
        $this->undoStack->push($arr);
        $this->processQueue->enqueue(count($this->entries) - 1);
    }

    public function delete($index) {
        if (isset($this->entries[$index])) {
            $this->undoStack->push($this->entries[$index]);
            unset($this->entries[$index]);
            $this->entries = array_values($this->entries);
            $this->storage->write($this->entries);
            $this->rebuildProcessQueue();
            return true;
        }
        return false;
    }

    public function undoLast() {
        if ($this->undoStack->isEmpty()) return false;
        $last = $this->undoStack->pop();
        foreach ($this->entries as $i => $e) {
            if ($e['tanggal'] === $last['tanggal'] && $e['mood'] === $last['mood']) {
                unset($this->entries[$i]);
                $this->entries = array_values($this->entries);
                $this->storage->write($this->entries);
                $this->rebuildProcessQueue();
                return true;
            }
        }
        return false;
    }

    private function rebuildProcessQueue() {
        $this->processQueue = new Queue();
        foreach ($this->entries as $i => $_) $this->processQueue->enqueue($i);
    }

    public function processQueueAndGetAverageAir() {
        if ($this->processQueue->isEmpty()) return 0.0;
        $sum = 0; $count = 0; $temp = [];
        while (!$this->processQueue->isEmpty()) {
            $idx = $this->processQueue->dequeue();
            $temp[] = $idx;
            if (isset($this->entries[$idx])) {
                $sum += (int)($this->entries[$idx]['airminum'] ?? 0);
                $count++;
            }
        }
        foreach ($temp as $i) $this->processQueue->enqueue($i);
        return $count ? $sum / $count : 0.0;
    }

    public function all() { return $this->entries; }
}


function mapMoodToLevel($mood) {
    $clean = trim(strtolower(strip_tags((string)$mood)));
    switch ($clean) {
        case 'senang':
        case '😊 senang':
        case 'senang😊':
        case 'puas':
            return 3;
        case 'biasa':
        case '😐 biasa':
            return 2;
        case 'sedih':
        case '😢 sedih':
            return 1;
        default:
            return 2;
    }
}


$storage = new JSONStorage($filePath);
$manager = new JournalManager($storage);

/* DELETE */
if (isset($_GET['hapus'])) {
    $idx = (int)$_GET['hapus'];
    if ($manager->delete($idx)) {
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

if (isset($_GET['undo'])) {
    if ($manager->undoLast()) {
        header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $manager->addFromPost($_POST);
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

$data = $manager->all();
$avgAir = $manager->processQueueAndGetAverageAir();

$chartLabels = array_map(function($d){
    return $d['tanggal'] ?? date('Y-m-d');
}, $data);

$chartData = array_map(function($d){
    return mapMoodToLevel($d['mood'] ?? 'Biasa');
}, $data);