<?php

declare(strict_types=1);

class DomainChecker
{
    private const UA_URL = 'https://raw.githubusercontent.com/HyperBeats/User-Agent-List/main/useragents-desktop.txt';

    private const UA_FILE = 'useragents_local.txt';

    private array $userAgents = [];
    private int $maxRetries = 1;
    private int $timeout = 10;
    private int $maxWorkers = 5; 

    private ?string $outputFile = null;

    public function __construct()
    {
        echo <<<Information
        Ini adalah alat yang digunakan untuk melakukan pengecekan HTTP Status Code 200 pada domain
        Alat ini dibuat dengan menggunakan PHP 8.4 (Multi-Threaded via cURL Multi)

        ! PLEASE MAKE A THIS IS TOOL FOR EDUCATION ! \n
        Information;

        echo "========== Let's GO ========== \n";

        $this->initializeUserAgents();
    }

    private function initializeUserAgents(): void
    {
        echo "[*] Proses pengambilan daftar User-Agent dari remote source .... \n";

        $context = stream_context_create([
            "http" => ["timeout" => 10]
        ]);

        $content = @file_get_contents(self::UA_URL, false, $context);

        if ($content !== false && !empty($content)) {
            $this->userAgents = array_filter(array_map('trim', preg_split('/\R/', $content)));
            echo "[+] Daftar User-Agent berhasil fetch ke memori (tanpa menyimpan file lokal). \n\n";
        } else {
            echo "[!] Gagal pengambilan User-Agent. Mencoba memuat file lokal sebagai fallback...\n";

            if (file_exists(self::UA_FILE)) {
                $this->userAgents = array_filter(array_map('trim', file(self::UA_FILE)));
                echo "[+] Menggunakan daftar User-Agent dari file lokal.\n\n";
            } else {
                die("[x] File lokal tidak ditemukan dan koneksi internet error...\n\n");
            }
        }

        if (empty($this->userAgents)) {
            die("[x] Daftar User-Agent kosong.\n\n");
        }
    }

    private function getRandomUserAgent(): string
    {
        return $this->userAgents[array_rand($this->userAgents)];
    }

    public function runInteractive(): void
    {

        $this->maxRetries = (int) $this->promptInput("Masukkan jumlah Retry (default 1): ", "1");
        $this->timeout    = (int) $this->promptInput("Masukkan Timeout dalam detik (default 5): ", "5");

        $inputWorker = (int) $this->promptInput("Masukkan jumlah Max Worker/Threads (default 5): ", "5");

        $this->maxWorkers = ($inputWorker > 0) ? $inputWorker : 5;

        echo "\nPilih Mode Target:\n";
        echo "[1] Single Domain\n";
        echo "[2] Load dari File\n";
        $mode = $this->promptInput("Pilihan (1/2): ");

        $targets = [];

        if ($mode == '1') {
            $domain = $this->promptInput("Masukkan Domain (misal: example.com): ");
            if (!empty($domain)) {
                $targets[] = $domain;
            }
        } elseif ($mode == '2') {
            $filename = $this->promptInput("Masukkan nama file list domain (default: subdomains.txt): ", "subdomains.txt");

            if (!file_exists($filename)) {
                die("[x] File '$filename' tidak ditemukan di direktori... \n");
            }

            $targets = array_filter(array_map('trim', file($filename)));
            echo "[+] Mengambil " . count($targets) . " domain dari file '$filename'.\n";
        } else {
            die("[x] Pilihan tidak valid.\n");
        }

        echo "\n[OPSIONAL] Simpan hasil 200 OK ke file?\n";
        $saveChoice = strtolower($this->promptInput("Simpan? (y/n) [default: n]: ", "n"));

        if ($saveChoice === 'y') {
            $this->outputFile = $this->promptInput("Masukkan nama file output (default: live_domains.txt): ", "live_domains.txt");
            echo "[+] Hasil 200 OK akan disimpan ke: " . $this->outputFile . "\n";
        }

        echo <<<Main
        \n++++++++++ Memulai Proses (Concurrency: $this->maxWorkers) ++++++++++ \n
        Main;

        $this->processBatch($targets);

        echo "\n[+] Selesai.\n";
        if ($this->outputFile) {
            echo "[+] Data tersimpan di file: " . $this->outputFile . "\n";
        }
    }

    private function processBatch(array $targets): void
    {
        $mh = curl_multi_init();
        $runningHandles = []; 

        $queue = []; 

        foreach ($targets as $t) {
            $queue[] = ['url' => $t, 'attempt' => 1];
        }

        $active = 0;

        do {

            while (count($runningHandles) < $this->maxWorkers && !empty($queue)) {
                $task = array_shift($queue);
                $urlRaw = $task['url'];

                $url = $urlRaw;
                if (!preg_match("~^(?:f|ht)tps?://~i", $urlRaw)) {
                    $url = "https://" . $urlRaw;
                }

                $ua = $this->getRandomUserAgent();
                $ch = $this->createHandle($url, $ua);

                curl_multi_add_handle($mh, $ch);

                $chId = (int)$ch;
                $runningHandles[$chId] = [
                    'url_raw' => $urlRaw, 

                    'url_final' => $url,
                    'attempt' => $task['attempt'],
                    'ua_short' => substr($ua, 0, 30) . "..."
                ];
                echo "[Start] {$url} \n";
            }

            $status = curl_multi_exec($mh, $active);

            if ($active > 0) {
                curl_multi_select($mh);
            }

            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                $chId = (int)$ch;
                $taskData = $runningHandles[$chId] ?? null;

                if ($taskData) {
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_multi_remove_handle($mh, $ch);
                    curl_close($ch);
                    unset($runningHandles[$chId]);

                    if ($httpCode > 0) {

                        if ($httpCode === 200) {
                            echo "\033[32m[200 OK]\033[0m {$taskData['url_final']} \n";

                            if ($this->outputFile !== null) {
                                file_put_contents($this->outputFile, $taskData['url_final'] . PHP_EOL, FILE_APPEND);
                            }
                        } else {
                            echo "\033[33m[$httpCode]\033[0m {$taskData['url_final']} \n";
                        }
                    } else {

                        echo "\033[31m[FAIL]\033[0m {$taskData['url_final']} \n";

                        if ($taskData['attempt'] < $this->maxRetries) {

                            $queue[] = [
                                'url' => $taskData['url_raw'],
                                'attempt' => $taskData['attempt'] + 1
                            ];
                        }
                    }
                }
            }

        } while ($active > 0 || !empty($queue));

        curl_multi_close($mh);
    }

    private function createHandle(string $url, string $userAgent): \CurlHandle
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false, 

            CURLOPT_NOBODY => true,  

            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_SSL_VERIFYHOST => 0, 
            CURLOPT_SSL_VERIFYPEER => 0
        ]);
        return $ch;
    }

    private function promptInput(string $prompt, string $default = ""): string
    {
        echo $prompt;
        $handle = fopen("php://stdin", "r");
        $line = trim(fgets($handle));
        fclose($handle);
        return empty($line) ? $default : $line;
    }
}

$checker = new DomainChecker();
$checker->runInteractive();