<?php
// ============================================================
// SISTEM ABSENSI - BACKEND API (PHP & MySQL)
// ============================================================

// 1. PENGATURAN CORS (SANGAT PENTING UNTUK NETLIFY)
header("Access-Control-Allow-Origin: *"); // Mengizinkan Netlify mengakses API ini
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
header("Content-Type: application/json; charset=UTF-8");

// Tangani Preflight Request dari Browser (CORS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// 2. KONEKSI DATABASE (PDO)
$host = "localhost";
$db_name = "db_absensi";
$username = "root"; 
$password = "";

try {
    $conn = new PDO("mysql:host=" . $host . ";dbname=" . $db_name, $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $exception) {
    echo json_encode(["success" => false, "message" => "Koneksi database gagal: " . $exception->getMessage()]);
    exit();
}

// 3. HELPER FUNCTIONS
function jsonResponse($success, $message, $data = null) {
    echo json_encode(["success" => $success, "message" => $message, "data" => $data]);
    exit();
}

// Haversine Formula (Sama dengan getDistanceMeter di GS)
function getDistanceMeter($lat1, $lon1, $lat2, $lon2) {
    $R = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon/2) * sin($dLon/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return $R * $c;
}

// Fungsi Simpan Foto Base64 ke Lokal
function saveBase64Image($base64Data, $nip, $prefix) {
    if (!$base64Data) return "";
    $dir = 'uploads/';
    if (!file_exists($dir)) mkdir($dir, 0777, true);
    
    // Bersihkan prefix base64
    $image_parts = explode(";base64,", $base64Data);
    if(count($image_parts) < 2) return $base64Data; // Jika bukan base64 (misal URL biasa), kembalikan aslinya
    
    $image_base64 = base64_decode($image_parts[1]);
    $fileName = $dir . $nip . '_' . $prefix . '_' . time() . '.jpg';
    file_put_contents($fileName, $image_base64);
    
    // Kembalikan URL lokal (Sesuaikan domain Anda nanti jika online)
    return "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/" . $fileName;
}

// 4. MEMBACA PAYLOAD REQUEST
$requestMethod = $_SERVER["REQUEST_METHOD"];
$data = [];

if ($requestMethod === 'GET') {
    $data = $_GET;
} elseif ($requestMethod === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);
}

$action = isset($data['action']) ? $data['action'] : '';

// 5. ROUTER UTAMA
try {
    switch ($action) {
        
        // --- GET CONFIG ---
        case 'getConfig':
            $stmt = $conn->query("SELECT * FROM config");
            $configs = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            jsonResponse(true, 'Config loaded', $configs);
            break;

        // --- AUTH & PROFILE ---
        case 'login':
            $nip = trim($data['nip']);
            $pass = trim($data['password']);
            
            $stmt = $conn->prepare("SELECT NIP, Nama_Lengkap, Jabatan, Status, Foto_Profile_URL FROM karyawan WHERE NIP = :nip AND Password = :pass");
            $stmt->execute(['nip' => $nip, 'pass' => $pass]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) jsonResponse(false, 'NIP atau Password salah');
            if (strtolower($user['Status']) !== 'aktif') jsonResponse(false, 'Akun tidak aktif, hubungi admin');
            
            jsonResponse(true, 'Login berhasil', $user);
            break;

        case 'adminLogin':
            $userReq = trim($data['username']);
            $passReq = trim($data['password']);
            
            $stmt = $conn->query("SELECT * FROM config WHERE config_key IN ('ADMIN_USERNAME', 'ADMIN_PASSWORD')");
            $conf = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            if ($userReq === $conf['ADMIN_USERNAME'] && $passReq === $conf['ADMIN_PASSWORD']) {
                jsonResponse(true, 'Login Admin Berhasil', ['role' => 'admin', 'username' => $userReq]);
            } else {
                jsonResponse(false, 'Username atau Password Admin salah!');
            }
            break;

        case 'getProfile':
            $nip = trim($data['nip']);
            $stmt = $conn->prepare("SELECT NIP, Nama_Lengkap, Jabatan, Status, Foto_Profile_URL FROM karyawan WHERE NIP = :nip");
            $stmt->execute(['nip' => $nip]);
            jsonResponse(true, 'Profile', $stmt->fetch(PDO::FETCH_ASSOC));
            break;

        // --- ABSENSI ---
        case 'absen':
            $nip = trim($data['nip']);
            $type = $data['type'];
            $lat = (float)$data['lat'];
            $lng = (float)$data['lng'];
            $photoBase64 = isset($data['photoBase64']) ? $data['photoBase64'] : '';

            if (!$nip || !$type || !$lat || !$lng) jsonResponse(false, 'Data tidak lengkap (nip, type, lat, lng wajib)');

            // Validasi Lokasi
            $stmtLoc = $conn->query("SELECT * FROM lokasi_kantor");
            $lokasiList = $stmtLoc->fetchAll(PDO::FETCH_ASSOC);
            $nearestDist = INF;
            $namaLokasi = "Bebas (Beta)";

            foreach ($lokasiList as $loc) {
                $dist = getDistanceMeter($lat, $lng, (float)$loc['Latitude'], (float)$loc['Longitude']);
                if ($dist < $nearestDist) {
                    $nearestDist = $dist;
                    $namaLokasi = $loc['Nama_Lokasi'];
                }
            }

            // Batas 100 Meter
            if ($nearestDist > 100) jsonResponse(false, "Lokasi di luar radius absen. Terdekat: $namaLokasi (jarak " . round($nearestDist) . " meter, maks 100m)");

            $tgl = date('Y-m-d');
            $jam = date('H:i:s');
            $lokasiStr = "$lat,$lng";
            
            // Simpan Foto
            $photoUrl = saveBase64Image($photoBase64, $nip, $type);

            $stmtCek = $conn->prepare("SELECT * FROM absensi WHERE NIP = :nip AND Tanggal = :tgl");
            $stmtCek->execute(['nip' => $nip, 'tgl' => $tgl]);
            $absenHariIni = $stmtCek->fetch(PDO::FETCH_ASSOC);

            if ($type === 'masuk') {
                if ($absenHariIni) jsonResponse(false, 'Anda sudah absen masuk hari ini');

                // Cek Keterlambatan
                $stmtConf = $conn->query("SELECT config_value FROM config WHERE config_key = 'JAM_MASUK_NORMAL'");
                $jamNormal = $stmtConf->fetchColumn() ?: '08:00:00';
                $status = ($jam > $jamNormal) ? 'Terlambat' : 'Hadir';
                $id = 'A' . date('YmdHis');

                $stmtIns = $conn->prepare("INSERT INTO absensi (ID, NIP, Tanggal, Jam_Masuk, Lokasi_Masuk, Foto_Masuk_URL, Status) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmtIns->execute([$id, $nip, $tgl, $jam, $lokasiStr, $photoUrl, $status]);

                jsonResponse(true, 'Absen masuk berhasil', ['jam' => $jam, 'status' => $status, 'lokasi' => $namaLokasi]);

            } else {
                if (!$absenHariIni) jsonResponse(false, 'Anda belum absen masuk hari ini');
                if ($absenHariIni['Jam_Pulang']) jsonResponse(false, 'Anda sudah absen pulang hari ini');

                $stmtUpd = $conn->prepare("UPDATE absensi SET Jam_Pulang=?, Lokasi_Pulang=?, Foto_Pulang_URL=? WHERE NIP=? AND Tanggal=?");
                $stmtUpd->execute([$jam, $lokasiStr, $photoUrl, $nip, $tgl]);

                jsonResponse(true, 'Absen pulang berhasil', ['jam' => $jam, 'lokasi' => $namaLokasi]);
            }
            break;

        case 'getAbsensiByNip':
            $nip = trim($data['nip']);
            $bulan = isset($data['bulan']) ? $data['bulan'] : '';
            $query = "SELECT * FROM absensi WHERE NIP = :nip";
            $params = ['nip' => $nip];
            if ($bulan) { $query .= " AND Tanggal LIKE :bln"; $params['bln'] = $bulan . '%'; }
            $query .= " ORDER BY Tanggal DESC";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            jsonResponse(true, 'Data', $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        // --- ADMIN GETTERS ---
        case 'getAllKaryawan':
            $stmt = $conn->query("SELECT NIP, Nama_Lengkap, Jabatan, Status, Foto_Profile_URL FROM karyawan");
            jsonResponse(true, 'Data Karyawan', $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'getAllAbsensi':
            $bulan = isset($data['bulan']) ? $data['bulan'] : '';
            $query = "SELECT a.*, k.Nama_Lengkap as Nama FROM absensi a JOIN karyawan k ON a.NIP = k.NIP";
            $params = [];
            if ($bulan) { $query .= " WHERE a.Tanggal LIKE :bln"; $params['bln'] = $bulan . '%'; }
            $query .= " ORDER BY a.Tanggal DESC";
            $stmt = $conn->prepare($query);
            $stmt->execute($params);
            jsonResponse(true, 'Semua Absensi', $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;
            
        case 'getAllPengajuan':
            $stmt = $conn->query("SELECT * FROM pengajuan ORDER BY Tanggal_Pengajuan DESC");
            jsonResponse(true, 'Semua Pengajuan', $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        case 'approvePengajuan':
        case 'rejectPengajuan':
            $id = $data['id'];
            $catatan = isset($data['catatan']) ? $data['catatan'] : '';
            $status = ($action === 'approvePengajuan') ? 'Approved' : 'Rejected';
            $stmt = $conn->prepare("UPDATE pengajuan SET Status = ?, Catatan_Admin = ? WHERE ID = ?");
            $stmt->execute([$status, $catatan, $id]);
            jsonResponse(true, "Status menjadi $status");
            break;

        // Dashboard Summary (Sederhana via SQL)
        case 'getDashboardSummary':
            $bulan = isset($data['bulan']) ? $data['bulan'] : date('Y-m');
            $stmt = $conn->prepare("
                SELECT k.NIP, k.Nama_Lengkap as Nama, k.Jabatan,
                (SELECT COUNT(*) FROM absensi WHERE NIP = k.NIP AND Tanggal LIKE ? AND Status IN ('Hadir','Terlambat')) as Jumlah_Masuk,
                (SELECT COUNT(*) FROM pengajuan WHERE NIP = k.NIP AND Jenis = 'Izin' AND Status = 'Approved' AND Tanggal_Mulai LIKE ?) as Jumlah_Izin,
                (SELECT COUNT(*) FROM pengajuan WHERE NIP = k.NIP AND Jenis = 'Sakit' AND Status = 'Approved' AND Tanggal_Mulai LIKE ?) as Jumlah_Sakit,
                (SELECT COUNT(*) FROM pengajuan WHERE NIP = k.NIP AND Jenis = 'Cuti' AND Status = 'Approved' AND Tanggal_Mulai LIKE ?) as Jumlah_Cuti
                FROM karyawan k
            ");
            $param = $bulan . "%";
            $stmt->execute([$param, $param, $param, $param]);
            jsonResponse(true, 'Summary', $stmt->fetchAll(PDO::FETCH_ASSOC));
            break;

        default:
            jsonResponse(false, 'Action tidak dikenali: ' . $action);
            break;
    }

} catch (Exception $e) {
    jsonResponse(false, 'Error Server: ' . $e->getMessage());
}
?>