<?php
/**
 * API za radne naloge - MySQL verzija
 * 
 * Endpointi:
 * - POST   /login              - Prijava
 * - GET    /korisnici          - Svi korisnici
 * - GET    /korisnici/{id}     - Jedan korisnik
 * - POST   /korisnici          - Novi korisnik
 * - PUT    /korisnici/{id}     - Uredi korisnika
 * - DELETE /korisnici/{id}     - Obriši korisnika
 * - (isto za kupci, artikli, postupci, nalozi)
 */

// CORS Headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-User-Id');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Učitaj konfiguraciju baze
require_once __DIR__ . '/config.php';


// ============================================
// HELPER FUNKCIJE
// ============================================

function sendResponse($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================
// ROUTING
// ============================================

$method = $_SERVER['REQUEST_METHOD'];
$path = $_SERVER['PATH_INFO'] ?? $_SERVER['REQUEST_URI'] ?? '';
$path = parse_url($path, PHP_URL_PATH);
$path = str_replace(basename($_SERVER['SCRIPT_NAME']), '', $path);
$path = trim($path, '/');

$pathParts = explode('/', $path);
$endpoint = $pathParts[0] ?? '';
$id = isset($pathParts[1]) && is_numeric($pathParts[1]) ? (int)$pathParts[1] : null;

$userId = isset($_SERVER['HTTP_X_USER_ID']) ? (int)$_SERVER['HTTP_X_USER_ID'] : null;
$input = json_decode(file_get_contents('php://input'), true) ?? [];

$db = getDB();

// ============================================
// LOGIN
// ============================================
if ($endpoint === 'login' && $method === 'POST') {
    $korisnickoIme = $input['korisnickoIme'] ?? '';
    $lozinka = $input['lozinka'] ?? '';
    
    $stmt = $db->prepare("SELECT * FROM korisnici WHERE korisnicko_ime = ? AND aktivan = 1");
    $stmt->execute([$korisnickoIme]);
    $korisnik = $stmt->fetch();
    
    if ($korisnik && $korisnik['lozinka'] === $lozinka) {
        unset($korisnik['lozinka']);
        // Pretvori u format koji frontend očekuje
        sendResponse([
            'id' => (string)$korisnik['id'],
            'ime' => $korisnik['ime'],
            'prezime' => $korisnik['prezime'],
            'korisnickoIme' => $korisnik['korisnicko_ime'],
            'uloga' => $korisnik['uloga']
        ]);
    }
    
    sendError('Invalid credentials', 401);
}

// ============================================
// KORISNICI
// ============================================
if ($endpoint === 'korisnici') {
    
    // GET all
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("SELECT id, ime, prezime, korisnicko_ime, uloga, aktivan FROM korisnici ORDER BY ime, prezime");
        $korisnici = $stmt->fetchAll();
        
        // Formatiraj za frontend
        $result = array_map(function($k) {
            return [
                'id' => (string)$k['id'],
                'ime' => $k['ime'],
                'prezime' => $k['prezime'],
                'korisnickoIme' => $k['korisnicko_ime'],
                'uloga' => $k['uloga']
            ];
        }, $korisnici);
        
        sendResponse($result);
    }
    
    // GET by ID
    if ($method === 'GET' && $id) {
        $stmt = $db->prepare("SELECT id, ime, prezime, korisnicko_ime, uloga FROM korisnici WHERE id = ?");
        $stmt->execute([$id]);
        $korisnik = $stmt->fetch();
        
        if (!$korisnik) sendError('Korisnik not found', 404);
        
        sendResponse([
            'id' => (string)$korisnik['id'],
            'ime' => $korisnik['ime'],
            'prezime' => $korisnik['prezime'],
            'korisnickoIme' => $korisnik['korisnicko_ime'],
            'uloga' => $korisnik['uloga']
        ]);
    }
    
    // POST - create
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("INSERT INTO korisnici (ime, prezime, korisnicko_ime, uloga, lozinka) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $input['ime'] ?? '',
            $input['prezime'] ?? '',
            $input['korisnickoIme'] ?? '',
            $input['uloga'] ?? 'korisnik',
            $input['lozinka'] ?? ''
        ]);
        
        $newId = $db->lastInsertId();
        sendResponse(['success' => true, 'id' => $newId]);
    }
    
    // PUT - update
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Ako nema nove lozinke, ne diraj je
        if (!empty($input['lozinka'])) {
            $stmt = $db->prepare("UPDATE korisnici SET ime = ?, prezime = ?, korisnicko_ime = ?, uloga = ?, lozinka = ? WHERE id = ?");
            $stmt->execute([
                $input['ime'] ?? '',
                $input['prezime'] ?? '',
                $input['korisnickoIme'] ?? '',
                $input['uloga'] ?? 'korisnik',
                $input['lozinka'],
                $id
            ]);
        } else {
            $stmt = $db->prepare("UPDATE korisnici SET ime = ?, prezime = ?, korisnicko_ime = ?, uloga = ? WHERE id = ?");
            $stmt->execute([
                $input['ime'] ?? '',
                $input['prezime'] ?? '',
                $input['korisnickoIme'] ?? '',
                $input['uloga'] ?? 'korisnik',
                $id
            ]);
        }
        
        sendResponse(['success' => true]);
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("DELETE FROM korisnici WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// KUPCI - IZ CENTRALNE BAZE!
// ============================================
if ($endpoint === 'kupci') {
    $dbKupci = getCentralnaDB(); // Koristi centralnu bazu!
    
    // FIRMA_ID za ovaj program (Signal Print = 1)
    define('FIRMA_ID', 1);
    
    // GET all
    if ($method === 'GET' && !$id) {
        // JOIN s kupci_minimax za dohvat minimax_id za ovu firmu
        $stmt = $dbKupci->prepare("
            SELECT k.*, km.minimax_id 
            FROM kupci k
            LEFT JOIN kupci_minimax km ON k.id = km.kupac_id AND km.firma_id = ?
            ORDER BY k.naziv
        ");
        $stmt->execute([FIRMA_ID]);
        $kupci = $stmt->fetchAll();
        
        // Formatiraj za frontend (camelCase)
        $result = array_map(function($k) {
            return [
                'id' => (int)$k['id'],
                'minimax_id' => $k['minimax_id'] ?? null,
                'naziv' => $k['naziv'],
                'kontakt' => $k['kontakt'],
                'email' => $k['email'],
                'telefon' => $k['telefon'],
                'oib' => $k['oib'],
                'adresa' => $k['adresa'],
                'postanskiBroj' => $k['postanskiBroj'],
                'mjesto' => $k['mjesto'],
                'zupanija' => $k['zupanija'],
                'drzava' => $k['drzava'],
                'napomena' => $k['napomena']
            ];
        }, $kupci);
        
        sendResponse($result);
    }
    
    // GET by ID
    if ($method === 'GET' && $id) {
        $stmt = $dbKupci->prepare("
            SELECT k.*, km.minimax_id 
            FROM kupci k
            LEFT JOIN kupci_minimax km ON k.id = km.kupac_id AND km.firma_id = ?
            WHERE k.id = ?
        ");
        $stmt->execute([FIRMA_ID, $id]);
        $kupac = $stmt->fetch();
        
        if (!$kupac) sendError('Kupac not found', 404);
        
        sendResponse([
            'id' => (int)$kupac['id'],
            'minimax_id' => $kupac['minimax_id'] ?? null,
            'naziv' => $kupac['naziv'],
            'kontakt' => $kupac['kontakt'],
            'email' => $kupac['email'],
            'telefon' => $kupac['telefon'],
            'oib' => $kupac['oib'],
            'adresa' => $kupac['adresa'],
            'postanskiBroj' => $kupac['postanskiBroj'],
            'mjesto' => $kupac['mjesto'],
            'zupanija' => $kupac['zupanija'],
            'drzava' => $kupac['drzava'],
            'napomena' => $kupac['napomena']
        ]);
    }
    
    // POST - create
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Generiraj novi ID
        $maxId = $dbKupci->query("SELECT MAX(id) as max_id FROM kupci")->fetch()['max_id'];
        $newId = ($maxId ?? 0) + 1;
        
        // Kreiraj kupca (bez minimax_id - to ide u zasebnu tablicu)
        $stmt = $dbKupci->prepare("
            INSERT INTO kupci (id, naziv, kontakt, email, telefon, oib, adresa, postanskiBroj, mjesto, zupanija, drzava, napomena)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $newId,
            $input['naziv'] ?? '',
            $input['kontakt'] ?? '',
            $input['email'] ?? '',
            $input['telefon'] ?? '',
            $input['oib'] ?? '',
            $input['adresa'] ?? '',
            $input['postanskiBroj'] ?? '',
            $input['mjesto'] ?? '',
            $input['zupanija'] ?? 'Krapinsko-zagorska',
            $input['drzava'] ?? 'Hrvatska',
            $input['napomena'] ?? ''
        ]);
        
        // Ako je poslan minimax_id, spremi ga u kupci_minimax za ovu firmu
        if (!empty($input['minimax_id'])) {
            $stmt = $dbKupci->prepare("
                INSERT INTO kupci_minimax (kupac_id, firma_id, minimax_id) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE minimax_id = VALUES(minimax_id)
            ");
            $stmt->execute([$newId, FIRMA_ID, $input['minimax_id']]);
        }
        
        $input['id'] = $newId;
        sendResponse(['success' => true, 'id' => $newId, 'data' => $input]);
    }
    
    // PUT - update
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Ažuriraj kupca (bez minimax_id - to ide u zasebnu tablicu)
        $stmt = $dbKupci->prepare("
            UPDATE kupci SET 
                naziv = ?, kontakt = ?, email = ?, telefon = ?, oib = ?,
                adresa = ?, postanskiBroj = ?, mjesto = ?, zupanija = ?, drzava = ?, napomena = ?
            WHERE id = ?
        ");
        $stmt->execute([
            $input['naziv'] ?? '',
            $input['kontakt'] ?? '',
            $input['email'] ?? '',
            $input['telefon'] ?? '',
            $input['oib'] ?? '',
            $input['adresa'] ?? '',
            $input['postanskiBroj'] ?? '',
            $input['mjesto'] ?? '',
            $input['zupanija'] ?? 'Krapinsko-zagorska',
            $input['drzava'] ?? 'Hrvatska',
            $input['napomena'] ?? '',
            $id
        ]);
        
        // Ažuriraj minimax_id u kupci_minimax za ovu firmu
        if (isset($input['minimax_id'])) {
            if (!empty($input['minimax_id'])) {
                // Insert ili update
                $stmt = $dbKupci->prepare("
                    INSERT INTO kupci_minimax (kupac_id, firma_id, minimax_id) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE minimax_id = VALUES(minimax_id)
                ");
                $stmt->execute([$id, FIRMA_ID, $input['minimax_id']]);
            } else {
                // Ako je prazan, obriši vezu
                $stmt = $dbKupci->prepare("DELETE FROM kupci_minimax WHERE kupac_id = ? AND firma_id = ?");
                $stmt->execute([$id, FIRMA_ID]);
            }
        }
        
        $input['id'] = $id;
        sendResponse(['success' => true, 'data' => $input]);
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Hard delete iz centralne baze
        $stmt = $dbKupci->prepare("DELETE FROM kupci WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// ARTIKLI
// ============================================
if ($endpoint === 'artikli') {
    
    // GET all
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("
            SELECT a.*, k.naziv as kategorija
            FROM artikli a
            LEFT JOIN artikl_kategorije k ON k.id = a.kategorija_id
            WHERE a.aktivan = 1 
            ORDER BY k.naziv, a.naziv
        ");
        $artikli = $stmt->fetchAll();
        
        // Dohvati predefinirane postupke za sve artikle
        $stmt = $db->query("
            SELECT ap.artikl_id, ap.postupak_id, ap.redoslijed, ap.default_kolicina, ap.default_cijena, p.naziv as postupak_naziv
            FROM artikl_postupci ap
            JOIN postupci p ON p.id = ap.postupak_id
            ORDER BY ap.artikl_id, ap.redoslijed
        ");
        $sviPostupci = $stmt->fetchAll();
        
        // Grupiraj postupke po artikl_id
        $postupciPoArtiklu = [];
        foreach ($sviPostupci as $p) {
            $artikl_id = $p['artikl_id'];
            if (!isset($postupciPoArtiklu[$artikl_id])) {
                $postupciPoArtiklu[$artikl_id] = [];
            }
            $postupciPoArtiklu[$artikl_id][] = [
                'postupakId' => (int)$p['postupak_id'],
                'naziv' => $p['postupak_naziv'],
                'redoslijed' => (int)$p['redoslijed'],
                'defaultKolicina' => (float)$p['default_kolicina'],
                'defaultCijena' => (float)$p['default_cijena']
            ];
        }
        
        $result = array_map(function($a) use ($postupciPoArtiklu) {
            return [
                'id' => (int)$a['id'],
                'sifra' => $a['sifra'] ?? null,
                'barcode' => $a['barcode'] ?? null,
                'naziv' => $a['naziv'],
                'kategorija' => $a['kategorija'] ?? '',
                'kategorijaId' => $a['kategorija_id'] ? (int)$a['kategorija_id'] : null,
                'jedinicaMjere' => $a['jedinica_mjere'],
                'cijena' => (float)$a['cijena'],
                'kpdSifra' => $a['kpd_sifra'] ?? null,
                'kpdNaziv' => $a['kpd_naziv'] ?? null,
                'postupci' => $postupciPoArtiklu[$a['id']] ?? []
            ];
        }, $artikli);
        
        sendResponse($result);
    }
    
    // GET by ID
    if ($method === 'GET' && $id) {
        $stmt = $db->prepare("
            SELECT a.*, k.naziv as kategorija
            FROM artikli a
            LEFT JOIN artikl_kategorije k ON k.id = a.kategorija_id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $artikl = $stmt->fetch();
        
        if (!$artikl) sendError('Artikl not found', 404);
        
        // Dohvati predefinirane postupke
        $stmt = $db->prepare("
            SELECT ap.postupak_id, ap.redoslijed, ap.default_kolicina, ap.default_cijena, p.naziv as postupak_naziv
            FROM artikl_postupci ap
            JOIN postupci p ON p.id = ap.postupak_id
            WHERE ap.artikl_id = ?
            ORDER BY ap.redoslijed
        ");
        $stmt->execute([$id]);
        $postupci = $stmt->fetchAll();
        
        sendResponse([
            'id' => (int)$artikl['id'],
            'sifra' => $artikl['sifra'] ?? null,
            'barcode' => $artikl['barcode'] ?? null,
            'naziv' => $artikl['naziv'],
            'kategorija' => $artikl['kategorija'] ?? '',
            'kategorijaId' => $artikl['kategorija_id'] ? (int)$artikl['kategorija_id'] : null,
            'jedinicaMjere' => $artikl['jedinica_mjere'],
            'cijena' => (float)$artikl['cijena'],
            'kpdSifra' => $artikl['kpd_sifra'] ?? null,
            'kpdNaziv' => $artikl['kpd_naziv'] ?? null,
            'postupci' => array_map(function($p) {
                return [
                    'postupakId' => (int)$p['postupak_id'],
                    'naziv' => $p['postupak_naziv'],
                    'redoslijed' => (int)$p['redoslijed'],
                    'defaultKolicina' => (float)$p['default_kolicina'],
                    'defaultCijena' => (float)$p['default_cijena']
                ];
            }, $postupci)
        ]);
    }
    
    // POST
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Provjeri da li šifra već postoji
        if (!empty($input['sifra'])) {
            $stmt = $db->prepare("SELECT id FROM artikli WHERE sifra = ? AND aktivan = 1");
            $stmt->execute([$input['sifra']]);
            if ($stmt->fetch()) {
                sendError('Šifra "' . $input['sifra'] . '" već postoji. Molimo odaberite drugu šifru.', 400);
            }
        }
        
        // Provjeri da li barcode već postoji
        if (!empty($input['barcode'])) {
            $stmt = $db->prepare("SELECT id FROM artikli WHERE barcode = ? AND aktivan = 1");
            $stmt->execute([$input['barcode']]);
            if ($stmt->fetch()) {
                sendError('Barcode "' . $input['barcode'] . '" već postoji. Molimo odaberite drugi barcode.', 400);
            }
        }
        
        $stmt = $db->prepare("INSERT INTO artikli (sifra, barcode, naziv, kategorija_id, jedinica_mjere, cijena, kpd_sifra, kpd_naziv) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            !empty($input['sifra']) ? $input['sifra'] : null,
            !empty($input['barcode']) ? $input['barcode'] : null,
            $input['naziv'] ?? '',
            !empty($input['kategorijaId']) ? (int)$input['kategorijaId'] : null,
            $input['jedinicaMjere'] ?? 'kom',
            $input['cijena'] ?? 0,
            $input['kpdSifra'] ?? null,
            $input['kpdNaziv'] ?? null
        ]);
        
        $artiklId = $db->lastInsertId();
        
        // Spremi predefinirane postupke ako su poslani
        if (!empty($input['postupci'])) {
            $stmt = $db->prepare("INSERT INTO artikl_postupci (artikl_id, postupak_id, redoslijed, default_kolicina, default_cijena) VALUES (?, ?, ?, ?, ?)");
            foreach ($input['postupci'] as $index => $p) {
                $stmt->execute([
                    $artiklId,
                    $p['postupakId'],
                    $index,
                    $p['defaultKolicina'] ?? 1,
                    $p['defaultCijena'] ?? 0
                ]);
            }
        }
        
        sendResponse(['success' => true, 'id' => $artiklId]);
    }
    
    // PUT
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Provjeri da li šifra već postoji (kod drugog artikla)
        if (!empty($input['sifra'])) {
            $stmt = $db->prepare("SELECT id FROM artikli WHERE sifra = ? AND id != ? AND aktivan = 1");
            $stmt->execute([$input['sifra'], $id]);
            if ($stmt->fetch()) {
                sendError('Šifra "' . $input['sifra'] . '" već postoji kod drugog artikla. Molimo odaberite drugu šifru.', 400);
            }
        }
        
        // Provjeri da li barcode već postoji (kod drugog artikla)
        if (!empty($input['barcode'])) {
            $stmt = $db->prepare("SELECT id FROM artikli WHERE barcode = ? AND id != ? AND aktivan = 1");
            $stmt->execute([$input['barcode'], $id]);
            if ($stmt->fetch()) {
                sendError('Barcode "' . $input['barcode'] . '" već postoji kod drugog artikla. Molimo odaberite drugi barcode.', 400);
            }
        }
        
        $stmt = $db->prepare("UPDATE artikli SET sifra = ?, barcode = ?, naziv = ?, kategorija_id = ?, jedinica_mjere = ?, cijena = ?, kpd_sifra = ?, kpd_naziv = ? WHERE id = ?");
        $stmt->execute([
            !empty($input['sifra']) ? $input['sifra'] : null,
            !empty($input['barcode']) ? $input['barcode'] : null,
            $input['naziv'] ?? '',
            !empty($input['kategorijaId']) ? (int)$input['kategorijaId'] : null,
            $input['jedinicaMjere'] ?? 'kom',
            $input['cijena'] ?? 0,
            $input['kpdSifra'] ?? null,
            $input['kpdNaziv'] ?? null,
            $id
        ]);
        
        // Ažuriraj predefinirane postupke
        if (isset($input['postupci'])) {
            // Obriši stare
            $stmt = $db->prepare("DELETE FROM artikl_postupci WHERE artikl_id = ?");
            $stmt->execute([$id]);
            
            // Dodaj nove
            if (!empty($input['postupci'])) {
                $stmt = $db->prepare("INSERT INTO artikl_postupci (artikl_id, postupak_id, redoslijed, default_kolicina, default_cijena) VALUES (?, ?, ?, ?, ?)");
                foreach ($input['postupci'] as $index => $p) {
                    $stmt->execute([
                        $id,
                        $p['postupakId'],
                        $index,
                        $p['defaultKolicina'] ?? 1,
                        $p['defaultCijena'] ?? 0
                    ]);
                }
            }
        }
        
        sendResponse(['success' => true]);
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("UPDATE artikli SET aktivan = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// NEXT ARTIKL ŠIFRA - Generiranje sljedeće šifre
// ============================================
if ($endpoint === 'artikli-next-sifra') {
    if ($method === 'GET') {
        $kategorijaId = isset($_GET['kategorijaId']) ? (int)$_GET['kategorijaId'] : null;
        
        if (!$kategorijaId) {
            sendResponse(['sifra' => null]);
        }
        
        // Dohvati prefix kategorije
        $stmt = $db->prepare("SELECT prefix FROM artikl_kategorije WHERE id = ?");
        $stmt->execute([$kategorijaId]);
        $kategorija = $stmt->fetch();
        
        if (!$kategorija || !$kategorija['prefix']) {
            sendResponse(['sifra' => null]);
        }
        
        $prefix = $kategorija['prefix'];
        
        // Pronađi najviši broj za ovu kategoriju
        $stmt = $db->prepare("
            SELECT sifra 
            FROM artikli 
            WHERE kategorija_id = ? AND sifra LIKE ?
            ORDER BY sifra DESC 
            LIMIT 1
        ");
        $stmt->execute([$kategorijaId, $prefix . '%']);
        $lastArtikl = $stmt->fetch();
        
        if ($lastArtikl && $lastArtikl['sifra']) {
            // Izvuci broj iz šifre (npr. "MF-005" -> 5)
            preg_match('/(\d+)$/', $lastArtikl['sifra'], $matches);
            $lastNumber = isset($matches[1]) ? (int)$matches[1] : 0;
            $nextNumber = $lastNumber + 1;
        } else {
            // Prva šifra za ovu kategoriju
            $nextNumber = 1;
        }
        
        // Formatiraj s vodećim nulama (npr. 001, 002, 003)
        $nextSifra = $prefix . str_pad($nextNumber, 3, '0', STR_PAD_LEFT);
        
        sendResponse(['sifra' => $nextSifra]);
    }
}

// ============================================
// POSTUPCI
// ============================================
if ($endpoint === 'postupci') {
    
    // GET all
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("SELECT * FROM postupci WHERE aktivan = 1 ORDER BY naziv");
        $postupci = $stmt->fetchAll();
        
        $result = array_map(function($p) {
            return [
                'id' => (int)$p['id'],
                'naziv' => $p['naziv'],
                'opis' => $p['opis'],
                'trajanje' => $p['trajanje'] ? (int)$p['trajanje'] : null
            ];
        }, $postupci);
        
        sendResponse($result);
    }
    
    // GET by ID
    if ($method === 'GET' && $id) {
        $stmt = $db->prepare("SELECT * FROM postupci WHERE id = ?");
        $stmt->execute([$id]);
        $postupak = $stmt->fetch();
        
        if (!$postupak) sendError('Postupak not found', 404);
        
        sendResponse([
            'id' => (int)$postupak['id'],
            'naziv' => $postupak['naziv'],
            'opis' => $postupak['opis'],
            'trajanje' => $postupak['trajanje'] ? (int)$postupak['trajanje'] : null
        ]);
    }
    
    // POST
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("INSERT INTO postupci (naziv, opis, trajanje) VALUES (?, ?, ?)");
        $stmt->execute([
            $input['naziv'] ?? '',
            $input['opis'] ?? '',
            $input['trajanje'] ?? null
        ]);
        
        sendResponse(['success' => true, 'id' => $db->lastInsertId()]);
    }
    
    // PUT
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("UPDATE postupci SET naziv = ?, opis = ?, trajanje = ? WHERE id = ?");
        $stmt->execute([
            $input['naziv'] ?? '',
            $input['opis'] ?? '',
            $input['trajanje'] ?? null,
            $id
        ]);
        
        sendResponse(['success' => true]);
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("UPDATE postupci SET aktivan = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// NALOZI
// ============================================
if ($endpoint === 'nalozi') {
    
    // GET all
    if ($method === 'GET' && !$id) {
        // Provjeri je li user admin
        $isAdmin = false;
        if ($userId) {
            $stmtAdmin = $db->prepare("SELECT uloga FROM korisnici WHERE id = ?");
            $stmtAdmin->execute([$userId]);
            $userRow = $stmtAdmin->fetch();
            $isAdmin = ($userRow && $userRow['uloga'] === 'admin');
        }
        
        // Admin vidi sve, ostali samo neobrisane
        $whereClause = $isAdmin ? "" : "WHERE n.obrisan = 0";
        
        $stmt = $db->query("
            SELECT n.*, 
                   COALESCE(SUM(na.kolicina * na.cijena), 0) as ukupna_vrijednost,
                   kc.ime as kreirao_ime,
                   ku.ime as azurirao_ime,
                   ko.ime as obrisao_ime,
                   koi.ime as otpremnica_izdao_ime
            FROM nalozi n
            LEFT JOIN nalog_artikli na ON na.nalog_id = n.id
            LEFT JOIN korisnici kc ON kc.id = n.created_by
            LEFT JOIN korisnici ku ON ku.id = n.updated_by
            LEFT JOIN korisnici ko ON ko.id = n.obrisan_by
            LEFT JOIN korisnici koi ON koi.id = n.otpremnica_izdao
            $whereClause
            GROUP BY n.id
            ORDER BY n.datum DESC, n.id DESC
        ");
        $nalozi = $stmt->fetchAll();
        
        // Dohvati sve artikle
        $stmtArtikli = $db->query("
            SELECT na.id, na.nalog_id, na.naziv, na.kolicina, na.cijena, na.jedinica, na.format, na.opis, na.napomena, na.redoslijed
            FROM nalog_artikli na
            ORDER BY na.redoslijed, na.id
        ");
        $sviArtikli = $stmtArtikli->fetchAll();
        
        // Grupiraj artikle po nalog_id
        $artikliPoNalogu = [];
        foreach ($sviArtikli as $a) {
            $nalogId = $a['nalog_id'];
            if (!isset($artikliPoNalogu[$nalogId])) {
                $artikliPoNalogu[$nalogId] = [];
            }
            $artikliPoNalogu[$nalogId][] = [
                'id' => (string)$a['id'],
                'naziv' => $a['naziv'],
                'kolicina' => $a['kolicina'],
                'format' => $a['format'],
                'opis' => $a['opis'],
                'cijena' => $a['cijena'],
                'jedinica' => $a['jedinica'],
                'napomena' => $a['napomena'] ?? '',
                'podsjetnici' => []
            ];
        }
        
        // Dohvati sve podsjetnike (uključujući završene) s informacijom tko ih je kreirao
        $stmtPodsjetnici = $db->query("
            SELECT p.nalog_id, p.nalog_artikl_id, p.tekst, p.prioritet, p.zavrsen,
                   p.created_at, k.ime as kreirao_ime
            FROM podsjetnici p
            LEFT JOIN korisnici k ON k.id = p.created_by
            ORDER BY 
                p.zavrsen ASC,
                CASE p.prioritet WHEN 'visok' THEN 1 WHEN 'srednji' THEN 2 ELSE 3 END,
                p.created_at DESC
        ");
        $sviPodsjetnici = $stmtPodsjetnici->fetchAll();
        
        // Dodaj podsjetnike u artikle
        foreach ($sviPodsjetnici as $p) {
            $nalogId = $p['nalog_id'];
            $artiklId = $p['nalog_artikl_id'];
            if (isset($artikliPoNalogu[$nalogId])) {
                foreach ($artikliPoNalogu[$nalogId] as &$artikl) {
                    if ($artikl['id'] == $artiklId) {
                        $artikl['podsjetnici'][] = [
                            'tekst' => $p['tekst'],
                            'prioritet' => $p['prioritet'],
                            'zavrsen' => (bool)$p['zavrsen'],
                            'kreiraoIme' => $p['kreirao_ime'],
                            'createdAt' => $p['created_at']
                        ];
                        break;
                    }
                }
            }
        }
        
        $result = array_map(function($n) use ($artikliPoNalogu) {
            return [
                'id' => (string)$n['id'],
                'broj' => $n['broj'],
                'datum' => $n['datum'],
                'rokIzrade' => $n['rok_izrade'],
                'klijentId' => ($n['klijent_id'] && $n['klijent_id'] < 4294967295) ? (string)$n['klijent_id'] : null,
                'klijentNaziv' => $n['klijent_naziv'],
                'kontaktOsoba' => $n['kontakt_osoba'],
                'telefon' => $n['telefon'],
                'email' => $n['email'],
                'nazivNaloga' => $n['naziv_naloga'],
                'opisProblema' => $n['opis_problema'],
                'brojRacuna' => $n['broj_racuna'],
                'status' => $n['status'],
                'rasknjizen' => (bool)($n['rasknjizen'] ?? false),
                'obrisan' => (bool)($n['obrisan'] ?? false),
                'obrisanAt' => $n['obrisan_at'] ?? null,
                'obrisanBy' => $n['obrisan_by'] ? (string)$n['obrisan_by'] : null,
                'obrisaoIme' => $n['obrisao_ime'] ?? null,
                'otpremnicaIzdana' => (bool)($n['otpremnica_izdana'] ?? false),
                'otpremnicaBroj' => $n['otpremnica_broj'] ?? null,
                'otpremnicaDatum' => $n['otpremnica_datum'] ?? null,
                'otpremnicaIzdao' => $n['otpremnica_izdao'] ?? null,
                'otpremnicaIzdaoIme' => $n['otpremnica_izdao_ime'] ?? null,
                'createdAt' => $n['created_at'],
                'createdBy' => (string)$n['created_by'],
                'createdByName' => $n['kreirao_ime'],
                'updatedAt' => $n['updated_at'],
                'updatedBy' => $n['updated_by'] ? (string)$n['updated_by'] : null,
                'updatedByName' => $n['azurirao_ime'],
                'ukupnaVrijednost' => (float)$n['ukupna_vrijednost'],
                'artikli' => $artikliPoNalogu[$n['id']] ?? []
            ];
        }, $nalozi);
        
        sendResponse($result);
    }
    
    // GET attachments za nalog
    if ($method === 'GET' && $id && isset($pathParts[2]) && $pathParts[2] === 'attachments') {
        // Kreiraj tablicu ako ne postoji
        $db->exec("CREATE TABLE IF NOT EXISTS nalog_attachments (
            id INT PRIMARY KEY AUTO_INCREMENT,
            nalog_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            file_path VARCHAR(500) NOT NULL,
            file_size INT,
            mime_type VARCHAR(100),
            created_by INT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            deleted_at TIMESTAMP NULL
        )");

        $stmt = $db->prepare("
            SELECT a.*, k.ime as kreirao_ime
            FROM nalog_attachments a
            LEFT JOIN korisnici k ON k.id = a.created_by
            WHERE a.nalog_id = ? AND a.deleted_at IS NULL
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([$id]);
        $attachments = $stmt->fetchAll();

        $result = array_map(function($a) {
            return [
                'id' => (int)$a['id'],
                'filename' => $a['filename'],
                'originalFilename' => $a['original_filename'],
                'filePath' => $a['file_path'],
                'fileSize' => (int)$a['file_size'],
                'mimeType' => $a['mime_type'],
                'kreiraoIme' => $a['kreirao_ime'],
                'createdAt' => $a['created_at']
            ];
        }, $attachments);

        sendResponse($result);
    }

    // GET by ID - sa artiklima i postupcima
    if ($method === 'GET' && $id && !isset($pathParts[2])) {
        $stmt = $db->prepare("SELECT * FROM nalozi WHERE id = ?");
        $stmt->execute([$id]);
        $nalog = $stmt->fetch();
        
        if (!$nalog) sendError('Nalog not found', 404);
        
        // Dohvati artikle
        $stmtArtikli = $db->prepare("
            SELECT na.*, a.naziv as artikl_naziv, a.sifra as artikl_sifra, a.kpd_sifra as artikl_kpd_sifra 
            FROM nalog_artikli na
            LEFT JOIN artikli a ON a.id = na.artikl_id
            WHERE na.nalog_id = ?
            ORDER BY na.redoslijed, na.id
        ");
        $stmtArtikli->execute([$id]);
        $artikli = $stmtArtikli->fetchAll();
        
        // Dohvati materijale po artiklu
        $stmtMaterijali = $db->prepare("
            SELECT nm.*, m.naziv, m.jedinica_mjere
            FROM nalog_materijali nm
            JOIN materijali m ON m.id = nm.materijal_id
            WHERE nm.nalog_artikl_id = ? AND (nm.stornirano = 0 OR nm.stornirano IS NULL)
        ");
        
        // Dohvati podsjetnike po artiklu
        $stmtPodsjetnici = $db->prepare("
            SELECT p.*, k1.ime as kreirao_ime, k2.ime as zavrsio_ime
            FROM podsjetnici p
            LEFT JOIN korisnici k1 ON k1.id = p.created_by
            LEFT JOIN korisnici k2 ON k2.id = p.zavrsen_by
            WHERE p.nalog_artikl_id = ?
            ORDER BY p.zavrsen ASC, p.created_at DESC
        ");
        
        // Dohvati podsjetnike na razini naloga (bez artikla)
        $stmtNalogPodsjetnici = $db->prepare("
            SELECT p.*, k1.ime as kreirao_ime, k2.ime as zavrsio_ime
            FROM podsjetnici p
            LEFT JOIN korisnici k1 ON k1.id = p.created_by
            LEFT JOIN korisnici k2 ON k2.id = p.zavrsen_by
            WHERE p.nalog_id = ? AND p.nalog_artikl_id IS NULL
            ORDER BY p.zavrsen ASC, p.created_at DESC
        ");
        $stmtNalogPodsjetnici->execute([$id]);
        $nalogPodsjetnici = $stmtNalogPodsjetnici->fetchAll();
        
        // Dohvati SVE materijale za nalog (uključujući stare bez artikla)
        $stmtSviMaterijali = $db->prepare("
            SELECT nm.*, m.naziv, m.jedinica_mjere,
                   k.ime as kreirao_ime
            FROM nalog_materijali nm
            JOIN materijali m ON m.id = nm.materijal_id
            LEFT JOIN korisnici k ON k.id = nm.created_by
            WHERE nm.nalog_id = ? AND (nm.stornirano = 0 OR nm.stornirano IS NULL)
            ORDER BY nm.id DESC
        ");
        $stmtSviMaterijali->execute([$id]);
        $sviMaterijali = $stmtSviMaterijali->fetchAll();
        
        // Dohvati postupke
        $stmtPostupci = $db->prepare("
            SELECT np.*, p.naziv as postupak_naziv,
                   k.ime as zavrsen_ime, k.prezime as zavrsen_prezime
            FROM nalog_postupci np
            LEFT JOIN postupci p ON p.id = np.postupak_id
            LEFT JOIN korisnici k ON k.id = np.zavrsen_by
            WHERE np.nalog_id = ?
            ORDER BY np.redoslijed, np.id
        ");
        $stmtPostupci->execute([$id]);
        $postupci = $stmtPostupci->fetchAll();
        
        // Formatiraj
        $result = [
            'id' => (string)$nalog['id'],
            'broj' => $nalog['broj'],
            'datum' => $nalog['datum'],
            'rokIzrade' => $nalog['rok_izrade'],
            'klijentId' => ($nalog['klijent_id'] && $nalog['klijent_id'] < 4294967295) ? (string)$nalog['klijent_id'] : null,
            'klijentNaziv' => $nalog['klijent_naziv'],
            'kontaktOsoba' => $nalog['kontakt_osoba'],
            'telefon' => $nalog['telefon'],
            'email' => $nalog['email'],
            'nazivNaloga' => $nalog['naziv_naloga'],
            'opisProblema' => $nalog['opis_problema'],
            'brojRacuna' => $nalog['broj_racuna'],
            'status' => $nalog['status'],
            'rasknjizen' => (bool)($nalog['rasknjizen'] ?? false),
            'rasknjizenAt' => $nalog['rasknjizen_at'] ?? null,
            'createdAt' => $nalog['created_at'],
            'createdBy' => (string)$nalog['created_by'],
            'updatedAt' => $nalog['updated_at'],
            'updatedBy' => $nalog['updated_by'] ? (string)$nalog['updated_by'] : null,
            'artikli' => array_map(function($a) use ($db, $stmtMaterijali, $stmtPodsjetnici) {
                $postupci = [];
                if (!empty($a['postupci_json'])) {
                    $postupci = json_decode($a['postupci_json'], true) ?: [];
                }
                
                // Dohvati materijale za ovaj artikl
                $stmtMaterijali->execute([$a['id']]);
                $materijaliRows = $stmtMaterijali->fetchAll();
                $materijali = array_map(function($m) {
                    return [
                        'id' => (int)$m['id'],
                        'materijalId' => (string)$m['materijal_id'],
                        'naziv' => $m['naziv'],
                        'kolicina' => (string)$m['kolicina'],
                        'jedinicaMjere' => $m['jedinica_mjere'],
                        'cijena' => (string)$m['cijena']
                    ];
                }, $materijaliRows);
                
                // Dohvati podsjetnike za ovaj artikl
                $stmtPodsjetnici->execute([$a['id']]);
                $podsjetniciRows = $stmtPodsjetnici->fetchAll();
                $podsjetnici = array_map(function($p) {
                    return [
                        'id' => (int)$p['id'],
                        'tekst' => $p['tekst'],
                        'prioritet' => $p['prioritet'],
                        'rok' => $p['rok'],
                        'zavrsen' => (bool)$p['zavrsen'],
                        'createdAt' => $p['created_at'],
                        'kreiraoIme' => $p['kreirao_ime'],
                        'zavrsioIme' => $p['zavrsio_ime'],
                        'zavrsenAt' => $p['zavrsen_at']
                    ];
                }, $podsjetniciRows);
                
                return [
                    'id' => (string)$a['id'],
                    'artiklId' => (string)$a['artikl_id'],
                    'sifra' => $a['artikl_sifra'] ?? '',
                    'kpdSifra' => $a['artikl_kpd_sifra'] ?? '',
                    'naziv' => $a['naziv'] ?: $a['artikl_naziv'],
                    'kolicina' => (string)$a['kolicina'],
                    'jedinica' => $a['jedinica'] ?? 'kom',
                    'cijena' => (string)$a['cijena'],
                    'format' => $a['format'] ?? '',
                    'opis' => $a['opis'] ?? '',
                    'napomena' => $a['napomena'] ?? '',
                    'postupci' => $postupci,
                    'materijali' => $materijali,
                    'podsjetnici' => $podsjetnici
                ];
            }, $artikli),
            'postupci' => array_map(function($p) {
                return [
                    'id' => (string)$p['id'],
                    'postupakId' => (string)$p['postupak_id'],
                    'naziv' => $p['naziv'] ?: $p['postupak_naziv'],
                    'kolicina' => (string)($p['kolicina'] ?? 1),
                    'cijena' => (string)($p['cijena'] ?? 0),
                    'trajanjeMin' => $p['trajanje_min'] ? (int)$p['trajanje_min'] : null,
                    'zavrsen' => (bool)$p['zavrsen'],
                    'zavrsenBy' => $p['zavrsen_by'] ? (string)$p['zavrsen_by'] : null,
                    'zavrsenByIme' => $p['zavrsen_ime'] ?: null,
                    'zavrsenAt' => $p['zavrsen_at'],
                    'napomena' => $p['napomena'] ?? ''
                ];
            }, $postupci),
            'sviMaterijali' => array_map(function($m) {
                return [
                    'id' => (int)$m['id'],
                    'materijalId' => (string)$m['materijal_id'],
                    'nalogArtiklId' => $m['nalog_artikl_id'] ? (string)$m['nalog_artikl_id'] : null,
                    'naziv' => $m['naziv'],
                    'kolicina' => (string)$m['kolicina'],
                    'jedinicaMjere' => $m['jedinica_mjere'],
                    'cijena' => (string)$m['cijena'],
                    'vrijednost' => (float)$m['kolicina'] * (float)$m['cijena'],
                    'createdAt' => $m['created_at'],
                    'createdByName' => $m['kreirao_ime']
                ];
            }, $sviMaterijali)
        ];
        
        sendResponse($result);
    }
    
    // POST - create
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        try {
            $db->beginTransaction();
            
            // Generiraj broj naloga
            $broj = $input['broj'] ?? '';
            if (empty($broj)) {
                $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(broj, 4) AS UNSIGNED)) as max_broj FROM nalozi WHERE broj LIKE 'RN-%'");
                $row = $stmt->fetch();
                $nextBroj = ($row['max_broj'] ?? 0) + 1;
                $broj = 'RN-' . $nextBroj;
            }
            
            // Ubaci nalog
            // Osiguraj da je klijentId integer ili null
            $klijentId = null;
            if (!empty($input['klijentId'])) {
                $kid = $input['klijentId'];
                if (is_numeric($kid) && $kid > 0 && $kid < 4294967295) {
                    $klijentId = (int)$kid;
                }
            }
            
            $stmt = $db->prepare("
                INSERT INTO nalozi (broj, datum, rok_izrade, klijent_id, klijent_naziv, kontakt_osoba, telefon, email, naziv_naloga, opis_problema, broj_racuna, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $broj,
                $input['datum'] ?? date('Y-m-d'),
                $input['rokIzrade'] ?? null,
                $klijentId,
                $input['klijentNaziv'] ?? '',
                $input['kontaktOsoba'] ?? '',
                $input['telefon'] ?? '',
                $input['email'] ?? '',
                $input['nazivNaloga'] ?? '',
                $input['opisProblema'] ?? '',
                $input['brojRacuna'] ?? '',
                $input['status'] ?? 'u_tijeku',
                $userId
            ]);
            
            $nalogId = $db->lastInsertId();
            
            // Ubaci artikle s njihovim postupcima
            if (!empty($input['artikli']) && is_array($input['artikli'])) {
                $stmtArtikl = $db->prepare("
                    INSERT INTO nalog_artikli (nalog_id, artikl_id, naziv, kolicina, jedinica, cijena, format, opis, napomena, redoslijed, postupci_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($input['artikli'] as $i => $artikl) {
                    if (!empty($artikl['artiklId']) || !empty($artikl['naziv'])) {
                        $postupciJson = !empty($artikl['postupci']) ? json_encode($artikl['postupci']) : null;
                        $stmtArtikl->execute([
                            $nalogId,
                            !empty($artikl['artiklId']) ? $artikl['artiklId'] : null,
                            $artikl['naziv'] ?? '',
                            $artikl['kolicina'] ?? 1,
                            $artikl['jedinica'] ?? 'kom',
                            $artikl['cijena'] ?? 0,
                            $artikl['format'] ?? '',
                            $artikl['opis'] ?? '',
                            $artikl['napomena'] ?? '',
                            $i,
                            $postupciJson
                        ]);
                    }
                }
            }
            
            // Ubaci postupke (stari način - na razini naloga)
            if (!empty($input['postupci']) && is_array($input['postupci'])) {
                $stmtPostupak = $db->prepare("
                    INSERT INTO nalog_postupci (nalog_id, postupak_id, naziv, kolicina, cijena, trajanje_min, redoslijed)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($input['postupci'] as $i => $postupak) {
                    if (!empty($postupak['postupakId']) || !empty($postupak['naziv'])) {
                        $stmtPostupak->execute([
                            $nalogId,
                            !empty($postupak['postupakId']) ? $postupak['postupakId'] : null,
                            $postupak['naziv'] ?? '',
                            $postupak['kolicina'] ?? 1,
                            $postupak['cijena'] ?? 0,
                            !empty($postupak['trajanjeMin']) ? $postupak['trajanjeMin'] : null,
                            $i
                        ]);
                    }
                }
            }
            
            $db->commit();
            sendResponse(['success' => true, 'id' => $nalogId]);
            
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Failed to create nalog: ' . $e->getMessage(), 500);
        }
    }
    
    // PUT /nalozi/{id}/restore - Admin vraća obrisani nalog (MORA BITI PRIJE STANDARDNOG PUT!)
    if ($method === 'PUT' && $id && isset($_GET['restore'])) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Provjeri da je admin
        $stmtAdmin = $db->prepare("SELECT uloga FROM korisnici WHERE id = ?");
        $stmtAdmin->execute([$userId]);
        $user = $stmtAdmin->fetch();
        
        if (!$user || $user['uloga'] !== 'admin') {
            sendError('Samo admin može vratiti obrisane naloge', 403);
        }
        
        $db->beginTransaction();
        try {
            // Dohvati broj naloga
            $stmt = $db->prepare("SELECT broj FROM nalozi WHERE id = ?");
            $stmt->execute([$id]);
            $nalogBroj = $stmt->fetchColumn();
            
            // Dohvati stornirane materijale
            $stmt = $db->prepare("
                SELECT nm.id, nm.materijal_id, nm.kolicina, nm.cijena, nm.nalog_artikl_id, m.zaliha,
                       na.naziv as artikl_naziv, na.kolicina as artikl_kolicina, na.jedinica as artikl_jedinica
                FROM nalog_materijali nm
                JOIN materijali m ON m.id = nm.materijal_id
                LEFT JOIN nalog_artikli na ON na.id = nm.nalog_artikl_id
                WHERE nm.nalog_id = ? AND nm.stornirano = 1
            ");
            $stmt->execute([$id]);
            $materijali = $stmt->fetchAll();
            
            // Ponovo skini materijale sa zalihe
            foreach ($materijali as $mat) {
                $kolicina = abs((float)$mat['kolicina']);
                $stanjePrije = (float)$mat['zaliha'];
                $stanjePoslije = $stanjePrije - $kolicina;
                
                // Skini sa zalihe
                $db->prepare("UPDATE materijali SET zaliha = ? WHERE id = ?")->execute([
                    $stanjePoslije, $mat['materijal_id']
                ]);
                
                // Napravi napomenu
                $napomena = 'Vraćanje naloga ' . $nalogBroj;
                if (!empty($mat['artikl_naziv'])) {
                    $napomena .= ' / ' . $mat['artikl_naziv'] . ' (' . $mat['artikl_kolicina'] . ' ' . ($mat['artikl_jedinica'] ?: 'kom') . ')';
                }
                
                // Spremi IZLAZ knjiženje
                $db->prepare("
                    INSERT INTO materijal_knjizenja 
                    (materijal_id, tip, kolicina, cijena, napomena, nalog_id, nalog_artikl_id, stanje_prije, stanje_poslije, created_by)
                    VALUES (?, 'IZLAZ', ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $mat['materijal_id'],
                    -$kolicina,
                    $mat['cijena'],
                    $napomena,
                    $id,
                    $mat['nalog_artikl_id'],
                    $stanjePrije,
                    $stanjePoslije,
                    $userId
                ]);
                
                // Poništi storno
                $db->prepare("UPDATE nalog_materijali SET stornirano = 0, stornirano_at = NULL, stornirano_by = NULL WHERE id = ?")->execute([
                    $mat['id']
                ]);
            }
            
            // Vrati nalog
            $stmt = $db->prepare("UPDATE nalozi SET obrisan = 0, obrisan_at = NULL, obrisan_by = NULL WHERE id = ?");
            $stmt->execute([$id]);
            
            $db->commit();
            sendResponse(['success' => true, 'vracenoMaterijala' => count($materijali)]);
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Greška: ' . $e->getMessage(), 500);
        }
    }
    
    // PUT - update nalog
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        try {
            $db->beginTransaction();
            
            // Dohvati postojeći broj (ne može se mijenjati)
            $stmt = $db->prepare("SELECT broj FROM nalozi WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) sendError('Nalog not found', 404);
            
            // Update nalog
            // Osiguraj da je klijentId integer ili null
            $klijentId = null;
            if (!empty($input['klijentId'])) {
                $kid = $input['klijentId'];
                // Provjeri da nije neispravan ID
                if (is_numeric($kid) && $kid > 0 && $kid < 4294967295) {
                    $klijentId = (int)$kid;
                }
            }
            
            $stmt = $db->prepare("
                UPDATE nalozi SET
                    datum = ?, rok_izrade = ?, klijent_id = ?, klijent_naziv = ?,
                    kontakt_osoba = ?, telefon = ?, email = ?, naziv_naloga = ?,
                    opis_problema = ?, broj_racuna = ?, status = ?, updated_by = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $input['datum'] ?? date('Y-m-d'),
                $input['rokIzrade'] ?? null,
                $klijentId,
                $input['klijentNaziv'] ?? '',
                $input['kontaktOsoba'] ?? '',
                $input['telefon'] ?? '',
                $input['email'] ?? '',
                $input['nazivNaloga'] ?? '',
                $input['opisProblema'] ?? '',
                $input['brojRacuna'] ?? '',
                $input['status'] ?? 'u_tijeku',
                $userId,
                $id
            ]);
            
            // Dohvati mapiranje starih artikala: stari_id -> redoslijed
            $stmt = $db->prepare("SELECT id, redoslijed FROM nalog_artikli WHERE nalog_id = ?");
            $stmt->execute([$id]);
            $stariArtikli = [];
            while ($row = $stmt->fetch()) {
                $stariArtikli[$row['redoslijed']] = $row['id'];
            }
            
            // Obriši stare artikle
            $db->prepare("DELETE FROM nalog_artikli WHERE nalog_id = ?")->execute([$id]);
            
            if (!empty($input['artikli']) && is_array($input['artikli'])) {
                $stmtArtikl = $db->prepare("
                    INSERT INTO nalog_artikli (nalog_id, artikl_id, naziv, kolicina, jedinica, cijena, format, opis, napomena, redoslijed, postupci_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($input['artikli'] as $i => $artikl) {
                    if (!empty($artikl['artiklId']) || !empty($artikl['naziv'])) {
                        $postupciJson = !empty($artikl['postupci']) ? json_encode($artikl['postupci']) : null;
                        $stmtArtikl->execute([
                            $id,
                            !empty($artikl['artiklId']) ? $artikl['artiklId'] : null,
                            $artikl['naziv'] ?? '',
                            $artikl['kolicina'] ?? 1,
                            $artikl['jedinica'] ?? 'kom',
                            $artikl['cijena'] ?? 0,
                            $artikl['format'] ?? '',
                            $artikl['opis'] ?? '',
                            $artikl['napomena'] ?? '',
                            $i,
                            $postupciJson
                        ]);
                        
                        $nalogArtiklId = $db->lastInsertId();
                        
                        // Ažuriraj nalog_artikl_id za postojeće materijale i podsjetnike ovog artikla
                        if (isset($stariArtikli[$i])) {
                            $stariArtiklId = $stariArtikli[$i];
                            $db->prepare("UPDATE nalog_materijali SET nalog_artikl_id = ? WHERE nalog_artikl_id = ?")->execute([
                                $nalogArtiklId, $stariArtiklId
                            ]);
                            $db->prepare("UPDATE materijal_knjizenja SET nalog_artikl_id = ? WHERE nalog_artikl_id = ?")->execute([
                                $nalogArtiklId, $stariArtiklId
                            ]);
                            $db->prepare("UPDATE podsjetnici SET nalog_artikl_id = ? WHERE nalog_artikl_id = ?")->execute([
                                $nalogArtiklId, $stariArtiklId
                            ]);
                        }
                        
                        // Spremi SAMO NOVE materijale za ovaj artikl (bez ID-a znači novi)
                        if (!empty($artikl['materijali']) && is_array($artikl['materijali'])) {
                            foreach ($artikl['materijali'] as $mat) {
                                // Preskoči ako materijal već ima ID (već je spremljen)
                                if (!empty($mat['id'])) continue;
                                
                                if (!empty($mat['materijalId']) && !empty($mat['kolicina']) && floatval($mat['kolicina']) > 0) {
                                    $kolicina = floatval($mat['kolicina']);
                                    
                                    // Dohvati cijenu i trenutno stanje
                                    $stmtMat = $db->prepare("SELECT cijena, zaliha FROM materijali WHERE id = ?");
                                    $stmtMat->execute([$mat['materijalId']]);
                                    $matInfo = $stmtMat->fetch();
                                    $cijena = floatval($mat['cijena'] ?? $matInfo['cijena'] ?? 0);
                                    $stanjePrije = floatval($matInfo['zaliha'] ?? 0);
                                    $stanjePoslije = $stanjePrije - $kolicina;
                                    
                                    // Spremi u nalog_materijali
                                    $stmtMaterijal = $db->prepare("
                                        INSERT INTO nalog_materijali (nalog_id, nalog_artikl_id, materijal_id, kolicina, cijena, created_by)
                                        VALUES (?, ?, ?, ?, ?, ?)
                                    ");
                                    $stmtMaterijal->execute([
                                        $id,
                                        $nalogArtiklId,
                                        $mat['materijalId'],
                                        $kolicina,
                                        $cijena,
                                        $userId
                                    ]);
                                    
                                    // Dohvati broj naloga
                                    $stmtBroj = $db->prepare("SELECT broj FROM nalozi WHERE id = ?");
                                    $stmtBroj->execute([$id]);
                                    $nalogBroj = $stmtBroj->fetchColumn();
                                    
                                    // Napravi napomenu s brojem naloga i artiklom
                                    $artiklNaziv = $artikl['naziv'] ?? '';
                                    $artiklKol = $artikl['kolicina'] ?? '';
                                    $artiklJed = $artikl['jedinica'] ?? 'kom';
                                    $napomenaIzlaz = $nalogBroj . ' / ' . $artiklNaziv . ' (' . $artiklKol . ' ' . $artiklJed . ')';
                                    
                                    // Spremi u materijal_knjizenja (IZLAZ)
                                    $stmtKnjizenje = $db->prepare("
                                        INSERT INTO materijal_knjizenja 
                                        (materijal_id, tip, kolicina, cijena, napomena, nalog_id, nalog_artikl_id, stanje_prije, stanje_poslije, created_by)
                                        VALUES (?, 'IZLAZ', ?, ?, ?, ?, ?, ?, ?, ?)
                                    ");
                                    $stmtKnjizenje->execute([
                                        $mat['materijalId'],
                                        -$kolicina,  // negativno za izlaz
                                        $cijena,
                                        $napomenaIzlaz,
                                        $id,
                                        $nalogArtiklId,
                                        $stanjePrije,
                                        $stanjePoslije,
                                        $userId
                                    ]);
                                    
                                    // Ažuriraj zalihu
                                    $db->prepare("UPDATE materijali SET zaliha = ? WHERE id = ?")->execute([
                                        $stanjePoslije,
                                        $mat['materijalId']
                                    ]);
                                }
                            }
                            
                            // Označi nalog kao rasknjižen
                            $db->prepare("UPDATE nalozi SET rasknjizen = 1, rasknjizen_at = COALESCE(rasknjizen_at, NOW()) WHERE id = ?")->execute([$id]);
                        }
                        
                        // Spremi NOVE podsjetnike za ovaj artikl (bez ID-a znači novi)
                        if (!empty($artikl['podsjetnici']) && is_array($artikl['podsjetnici'])) {
                            foreach ($artikl['podsjetnici'] as $pod) {
                                // Preskoči ako podsjetnik već ima ID (već je spremljen) ili ako je završen
                                if (!empty($pod['id']) || !empty($pod['zavrsen'])) continue;
                                
                                if (!empty($pod['tekst'])) {
                                    $stmtPod = $db->prepare("
                                        INSERT INTO podsjetnici (nalog_id, nalog_artikl_id, tekst, prioritet, created_by)
                                        VALUES (?, ?, ?, ?, ?)
                                    ");
                                    $stmtPod->execute([
                                        $id,
                                        $nalogArtiklId,
                                        $pod['tekst'],
                                        $pod['prioritet'] ?? 'srednji',
                                        $userId
                                    ]);
                                }
                            }
                        }
                    }
                }
            }
            
            // Obriši stare postupke i dodaj nove
            $db->prepare("DELETE FROM nalog_postupci WHERE nalog_id = ?")->execute([$id]);
            if (!empty($input['postupci']) && is_array($input['postupci'])) {
                $stmtPostupak = $db->prepare("
                    INSERT INTO nalog_postupci (nalog_id, postupak_id, naziv, kolicina, cijena, trajanje_min, redoslijed)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                foreach ($input['postupci'] as $i => $postupak) {
                    if (!empty($postupak['postupakId']) || !empty($postupak['naziv'])) {
                        $stmtPostupak->execute([
                            $id,
                            !empty($postupak['postupakId']) ? $postupak['postupakId'] : null,
                            $postupak['naziv'] ?? '',
                            $postupak['kolicina'] ?? 1,
                            $postupak['cijena'] ?? 0,
                            !empty($postupak['trajanjeMin']) ? $postupak['trajanjeMin'] : null,
                            $i
                        ]);
                    }
                }
            }
            
            $db->commit();
            sendResponse(['success' => true]);
            
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Failed to update nalog: ' . $e->getMessage(), 500);
        }
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $db->beginTransaction();
        try {
            // Dohvati broj naloga
            $stmt = $db->prepare("SELECT broj FROM nalozi WHERE id = ?");
            $stmt->execute([$id]);
            $nalogBroj = $stmt->fetchColumn();
            
            // Prvo vrati sve materijale na zalihu
            $stmt = $db->prepare("
                SELECT nm.id, nm.materijal_id, nm.kolicina, nm.cijena, nm.nalog_artikl_id, m.zaliha,
                       na.naziv as artikl_naziv, na.kolicina as artikl_kolicina, na.jedinica as artikl_jedinica
                FROM nalog_materijali nm
                JOIN materijali m ON m.id = nm.materijal_id
                LEFT JOIN nalog_artikli na ON na.id = nm.nalog_artikl_id
                WHERE nm.nalog_id = ? AND (nm.stornirano = 0 OR nm.stornirano IS NULL)
            ");
            $stmt->execute([$id]);
            $materijali = $stmt->fetchAll();
            
            foreach ($materijali as $mat) {
                $kolicina = abs((float)$mat['kolicina']);
                $stanjePrije = (float)$mat['zaliha'];
                $stanjePoslije = $stanjePrije + $kolicina;
                
                // Vrati na zalihu
                $db->prepare("UPDATE materijali SET zaliha = ? WHERE id = ?")->execute([
                    $stanjePoslije, $mat['materijal_id']
                ]);
                
                // Napravi napomenu
                $napomena = 'Brisanje naloga ' . $nalogBroj;
                if (!empty($mat['artikl_naziv'])) {
                    $napomena .= ' / ' . $mat['artikl_naziv'] . ' (' . $mat['artikl_kolicina'] . ' ' . ($mat['artikl_jedinica'] ?: 'kom') . ')';
                }
                
                // Spremi STORNO knjiženje
                $db->prepare("
                    INSERT INTO materijal_knjizenja 
                    (materijal_id, tip, kolicina, cijena, napomena, nalog_id, nalog_artikl_id, stanje_prije, stanje_poslije, created_by)
                    VALUES (?, 'STORNO', ?, ?, ?, ?, ?, ?, ?, ?)
                ")->execute([
                    $mat['materijal_id'],
                    $kolicina,
                    $mat['cijena'],
                    $napomena,
                    $id,
                    $mat['nalog_artikl_id'],
                    $stanjePrije,
                    $stanjePoslije,
                    $userId
                ]);
                
                // Označi materijal kao storniran
                $db->prepare("UPDATE nalog_materijali SET stornirano = 1, stornirano_at = NOW(), stornirano_by = ? WHERE id = ?")->execute([
                    $userId, $mat['id']
                ]);
            }
            
            // Soft delete - označi nalog kao obrisan (ne briše fizički)
            $stmt = $db->prepare("UPDATE nalozi SET obrisan = 1, obrisan_at = NOW(), obrisan_by = ? WHERE id = ?");
            $stmt->execute([$userId, $id]);
            
            $db->commit();
            sendResponse(['success' => true, 'vracenoMaterijala' => count($materijali)]);
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Greška: ' . $e->getMessage(), 500);
        }
    }
}

// ============================================
// MINIMAX - Slanje predloška RAČUNA na osnovu radnog naloga
// ============================================
if ($endpoint === 'minimax-send-nalog') {
    if ($method === 'POST' && !empty($input['nalogId'])) {
        if (!$userId) sendError('Unauthorized', 401);
        
        require_once __DIR__ . '/minimax_config.php';
        
        $nalogId = (int)$input['nalogId'];
        
        try {
            // Dohvati nalog s detaljima
            $stmt = $db->prepare("SELECT * FROM nalozi WHERE id = ?");
            $stmt->execute([$nalogId]);
            $nalog = $stmt->fetch();
            
            if (!$nalog) sendError('Nalog not found', 404);
            
            // Dohvati kupca iz centralne baze
            $dbKupci = getCentralnaDB();
            $stmtKupac = $dbKupci->prepare("
                SELECT k.*, km.minimax_id
                FROM kupci k
                LEFT JOIN kupci_minimax km ON k.id = km.kupac_id AND km.firma_id = 1
                WHERE k.id = ?
            ");
            $stmtKupac->execute([$nalog['kupac_id']]);
            $kupac = $stmtKupac->fetch();
            
            if (!$kupac || !$kupac['minimax_id']) {
                sendError('Kupac nema Minimax ID - potrebno je prvo kreirati kupca u Minimax-u', 400);
            }
            
            // Dohvati artikle naloga
            $stmtArtikli = $db->prepare("SELECT * FROM nalog_artikli WHERE nalog_id = ? ORDER BY id");
            $stmtArtikli->execute([$nalogId]);
            $artikli = $stmtArtikli->fetchAll();
            
            // Dohvati postupke naloga
            $stmtPostupci = $db->prepare("SELECT * FROM nalog_postupci WHERE nalog_id = ? ORDER BY id");
            $stmtPostupci->execute([$nalogId]);
            $postupci = $stmtPostupci->fetchAll();
            
            // Pripremi stavke RAČUNA za Minimax
            $rows = [];
            
            // Dodaj artikle kao stavke računa
            foreach ($artikli as $art) {
                $rows[] = [
                    'ItemName' => $art['naziv'],
                    'Quantity' => (float)$art['kolicina'],
                    'UnitOfMeasurement' => $art['jedinica'] ?? 'kom',
                    'Price' => (float)$art['cijena'],
                    'VATPercent' => 25.0 // Default PDV 25%, možeš ovo učitati iz postavki
                ];
            }
            
            // Dodaj postupke kao usluge na računu
            foreach ($postupci as $post) {
                $rows[] = [
                    'ItemName' => $post['naziv'] . ($post['opis'] ? ' - ' . $post['opis'] : ''),
                    'Quantity' => (float)($post['kolicina'] ?? 1),
                    'UnitOfMeasurement' => 'usluga',
                    'Price' => (float)$post['cijena'],
                    'VATPercent' => 25.0
                ];
            }
            
            // Pripremi PREDLOŽAK RAČUNA za Minimax API
            $minimaxData = [
                'issuedInvoice' => [
                    'Status' => 'O', // O = Draft (predložak), I = Issued (konačan)
                    'InvoiceType' => 'R', // R = račun, P = proforma
                    'Customer' => [
                        'CustomerId' => (int)$kupac['minimax_id']
                    ],
                    'Date' => $nalog['datum'],
                    'DateDue' => $nalog['rok_izrade'] ?? date('Y-m-d', strtotime('+30 days')),
                    'DescriptionAbove' => 'Kreiran iz radnog naloga: ' . $nalog['broj'],
                    'DescriptionBelow' => $nalog['napomena'] ?? '',
                    'IssuedInvoiceRows' => $rows
                ]
            ];
            
            // Pošalji u Minimax - endpoint za dodavanje računa
            // Trebam dohvatiti OrganisationId iz prvog API poziva
            $orgs = minimaxApiCall('/currentuser/orgs', 'GET');
            if (empty($orgs['Rows'])) {
                throw new Exception('Nema dostupnih organizacija');
            }
            $orgId = $orgs['Rows'][0]['OrganisationId'];
            
            // Kreiraj predložak računa
            $result = minimaxApiCall("/orgs/{$orgId}/issuedinvoices", 'POST', $minimaxData);
            
            // Spremi info o slanju u bazu
            $minimaxInvoiceId = null;
            if (!empty($result['Headers']['Location'])) {
                // Ekstraktuj ID iz Location headera (zadnji dio URL-a)
                $parts = explode('/', $result['Headers']['Location']);
                $minimaxInvoiceId = end($parts);
            }
            
            $stmt = $db->prepare("
                UPDATE nalozi 
                SET minimax_poslano = 1, minimax_id = ?, minimax_poslano_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([
                $minimaxInvoiceId,
                $nalogId
            ]);
            
            sendResponse([
                'success' => true,
                'minimax_id' => $minimaxInvoiceId,
                'message' => 'Predložak računa uspješno kreiran u Minimax-u',
                'location' => $result['Headers']['Location'] ?? null
            ]);
            
        } catch (Exception $e) {
            sendError('Minimax greška: ' . $e->getMessage(), 500);
        }
    }
}

// ============================================
// NALOG-POSTUPCI - Toggle završen status
// ============================================
if ($endpoint === 'nalog-postupci') {
    
    // PUT - toggle zavrsen ili označi s odabranim korisnikom
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Dohvati trenutni status
        $stmt = $db->prepare("SELECT zavrsen FROM nalog_postupci WHERE id = ?");
        $stmt->execute([$id]);
        $postupak = $stmt->fetch();
        
        if (!$postupak) sendError('Postupak not found', 404);
        
        // Ako je proslijeđen zavrsenBy, koristi njega (admin odabir)
        // Inače toggle trenutno stanje
        $odabraniKorisnik = isset($input['zavrsenBy']) ? $input['zavrsenBy'] : null;
        $forceZavrsen = isset($input['zavrsen']) ? (bool)$input['zavrsen'] : null;
        
        if ($forceZavrsen !== null) {
            // Eksplicitno postavljanje statusa (npr. admin označava za drugog korisnika)
            $noviStatus = $forceZavrsen;
        } else {
            // Toggle
            $noviStatus = !$postupak['zavrsen'];
        }
        
        if ($noviStatus) {
            // Označava kao završen
            $korisnikZaOznaciti = $odabraniKorisnik ?: $userId;
            $stmt = $db->prepare("
                UPDATE nalog_postupci 
                SET zavrsen = 1, zavrsen_by = ?, zavrsen_at = NOW()
                WHERE id = ?
            ");
            $stmt->execute([$korisnikZaOznaciti, $id]);
        } else {
            // Poništava završenost
            $stmt = $db->prepare("
                UPDATE nalog_postupci 
                SET zavrsen = 0, zavrsen_by = NULL, zavrsen_at = NULL
                WHERE id = ?
            ");
            $stmt->execute([$id]);
        }
        
        // Vrati ažurirani postupak
        $stmt = $db->prepare("
            SELECT np.*, k.ime as zavrsen_ime, k.prezime as zavrsen_prezime
            FROM nalog_postupci np
            LEFT JOIN korisnici k ON k.id = np.zavrsen_by
            WHERE np.id = ?
        ");
        $stmt->execute([$id]);
        $updated = $stmt->fetch();
        
        sendResponse([
            'success' => true,
            'postupak' => [
                'id' => (string)$updated['id'],
                'zavrsen' => (bool)$updated['zavrsen'],
                'zavrsenBy' => $updated['zavrsen_by'] ? (string)$updated['zavrsen_by'] : null,
                'zavrsenByIme' => $updated['zavrsen_ime'] ?: null,
                'zavrsenAt' => $updated['zavrsen_at']
            ]
        ]);
    }
}

// ============================================
// ARTIKL KATEGORIJE
// ============================================
if ($endpoint === 'artikl-kategorije') {
    
    // GET all
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("SELECT * FROM artikl_kategorije ORDER BY naziv");
        $kategorije = $stmt->fetchAll();
        
        $result = array_map(function($k) {
            return [
                'id' => (int)$k['id'],
                'naziv' => $k['naziv'],
                'prefix' => $k['prefix'] ?? ''
            ];
        }, $kategorije);
        
        sendResponse($result);
    }
    
    // POST
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $naziv = $input['naziv'] ?? '';
        $prefix = $input['prefix'] ?? '';
        
        if (empty($naziv)) sendError('Naziv je obavezan', 400);
        
        // Ako prefix nije zadan, generiraj iz naziva (prve 2 slova + -)
        if (empty($prefix)) {
            $prefix = strtoupper(substr($naziv, 0, 2)) . '-';
        }
        
        // Provjeri da li već postoji
        $stmt = $db->prepare("SELECT id FROM artikl_kategorije WHERE naziv = ?");
        $stmt->execute([$naziv]);
        if ($stmt->fetch()) {
            sendError('Kategorija već postoji', 400);
        }
        
        $stmt = $db->prepare("INSERT INTO artikl_kategorije (naziv, prefix) VALUES (?, ?)");
        $stmt->execute([$naziv, $prefix]);
        
        sendResponse(['success' => true, 'id' => $db->lastInsertId()]);
    }
    
    // PUT
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $naziv = $input['naziv'] ?? '';
        $stariNaziv = $input['stariNaziv'] ?? '';
        
        if (empty($naziv)) sendError('Naziv je obavezan', 400);
        
        // Ažuriraj kategoriju
        $stmt = $db->prepare("UPDATE artikl_kategorije SET naziv = ? WHERE id = ?");
        $stmt->execute([$naziv, $id]);
        
        // Ažuriraj sve artikle s tom kategorijom
        if (!empty($stariNaziv)) {
            $stmt = $db->prepare("UPDATE artikli SET kategorija = ? WHERE kategorija = ?");
            $stmt->execute([$naziv, $stariNaziv]);
        }
        
        sendResponse(['success' => true]);
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Dohvati naziv kategorije
        $stmt = $db->prepare("SELECT naziv FROM artikl_kategorije WHERE id = ?");
        $stmt->execute([$id]);
        $kat = $stmt->fetch();
        
        if ($kat) {
            // Ukloni kategoriju s artikala
            $stmt = $db->prepare("UPDATE artikli SET kategorija = '' WHERE kategorija = ?");
            $stmt->execute([$kat['naziv']]);
        }
        
        // Obriši kategoriju
        $stmt = $db->prepare("DELETE FROM artikl_kategorije WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// MATERIJAL KATEGORIJE
// ============================================
if ($endpoint === 'materijal-kategorije') {
    
    // GET all
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("SELECT * FROM materijal_kategorije ORDER BY naziv");
        $kategorije = $stmt->fetchAll();
        
        $result = array_map(function($k) {
            return [
                'id' => (int)$k['id'],
                'naziv' => $k['naziv']
            ];
        }, $kategorije);
        
        sendResponse($result);
    }
    
    // POST
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $naziv = $input['naziv'] ?? '';
        
        if (empty($naziv)) sendError('Naziv je obavezan', 400);
        
        // Provjeri da li već postoji
        $stmt = $db->prepare("SELECT id FROM materijal_kategorije WHERE naziv = ?");
        $stmt->execute([$naziv]);
        if ($stmt->fetch()) {
            sendError('Kategorija već postoji', 400);
        }
        
        $stmt = $db->prepare("INSERT INTO materijal_kategorije (naziv) VALUES (?)");
        $stmt->execute([$naziv]);
        
        sendResponse(['success' => true, 'id' => $db->lastInsertId()]);
    }
    
    // PUT
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $naziv = $input['naziv'] ?? '';
        $stariNaziv = $input['stariNaziv'] ?? '';
        
        if (empty($naziv)) sendError('Naziv je obavezan', 400);
        
        // Ažuriraj kategoriju
        $stmt = $db->prepare("UPDATE materijal_kategorije SET naziv = ? WHERE id = ?");
        $stmt->execute([$naziv, $id]);
        
        // Ažuriraj sve materijale s tom kategorijom (tekst polje)
        if (!empty($stariNaziv)) {
            $stmt = $db->prepare("UPDATE materijali SET kategorija = ? WHERE kategorija = ?");
            $stmt->execute([$naziv, $stariNaziv]);
        }
        
        sendResponse(['success' => true]);
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Dohvati naziv kategorije
        $stmt = $db->prepare("SELECT naziv FROM materijal_kategorije WHERE id = ?");
        $stmt->execute([$id]);
        $kat = $stmt->fetch();
        
        if ($kat) {
            // Ukloni kategoriju s materijala (postavi na NULL za kategorija_id)
            $stmt = $db->prepare("UPDATE materijali SET kategorija = '', kategorija_id = NULL WHERE kategorija_id = ?");
            $stmt->execute([$id]);
        }
        
        // Obriši kategoriju
        $stmt = $db->prepare("DELETE FROM materijal_kategorije WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// MATERIJALI
// ============================================
if ($endpoint === 'materijali') {
    
    // GET all
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("
            SELECT m.*, mk.naziv as kategorija_naziv
            FROM materijali m
            LEFT JOIN materijal_kategorije mk ON mk.id = m.kategorija_id
            WHERE m.aktivan = 1 
            ORDER BY mk.naziv, m.naziv
        ");
        $materijali = $stmt->fetchAll();
        
        $result = array_map(function($m) {
            return [
                'id' => (int)$m['id'],
                'naziv' => $m['naziv'],
                'kategorija' => $m['kategorija_naziv'] ?? $m['kategorija'] ?? '', // Compatibility
                'kategorijaId' => $m['kategorija_id'] ? (int)$m['kategorija_id'] : null,
                'jedinicaMjere' => $m['jedinica_mjere'],
                'cijena' => (float)$m['cijena'],
                'zaliha' => (float)$m['zaliha'],
                'minZaliha' => (float)$m['min_zaliha']
            ];
        }, $materijali);
        
        sendResponse($result);
    }
    
    // GET by ID
    if ($method === 'GET' && $id) {
        $stmt = $db->prepare("
            SELECT m.*, mk.naziv as kategorija_naziv
            FROM materijali m
            LEFT JOIN materijal_kategorije mk ON mk.id = m.kategorija_id
            WHERE m.id = ?
        ");
        $stmt->execute([$id]);
        $m = $stmt->fetch();
        
        if (!$m) sendError('Materijal not found', 404);
        
        sendResponse([
            'id' => (int)$m['id'],
            'naziv' => $m['naziv'],
            'kategorija' => $m['kategorija_naziv'] ?? $m['kategorija'] ?? '',
            'kategorijaId' => $m['kategorija_id'] ? (int)$m['kategorija_id'] : null,
            'jedinicaMjere' => $m['jedinica_mjere'],
            'cijena' => (float)$m['cijena'],
            'zaliha' => (float)$m['zaliha'],
            'minZaliha' => (float)$m['min_zaliha']
        ]);
    }
    
    // POST
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Dohvati naziv kategorije ako je zadan kategorijaId
        $kategorijaNaziv = '';
        if (!empty($input['kategorijaId'])) {
            $stmtKat = $db->prepare("SELECT naziv FROM materijal_kategorije WHERE id = ?");
            $stmtKat->execute([(int)$input['kategorijaId']]);
            $kat = $stmtKat->fetch();
            if ($kat) {
                $kategorijaNaziv = $kat['naziv'];
            }
        }
        
        $stmt = $db->prepare("INSERT INTO materijali (naziv, kategorija, kategorija_id, jedinica_mjere, cijena, zaliha, min_zaliha) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $input['naziv'] ?? '',
            $kategorijaNaziv, // Za compatibility
            !empty($input['kategorijaId']) ? (int)$input['kategorijaId'] : null,
            $input['jedinicaMjere'] ?? 'kom',
            $input['cijena'] ?? 0,
            $input['zaliha'] ?? 0,
            $input['minZaliha'] ?? 0
        ]);
        
        sendResponse(['success' => true, 'id' => $db->lastInsertId()]);
    }
    
    // PUT - NE dira zalihu (zaliha ide samo kroz knjiženja)
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Dohvati naziv kategorije ako je zadan kategorijaId
        $kategorijaNaziv = '';
        if (!empty($input['kategorijaId'])) {
            $stmtKat = $db->prepare("SELECT naziv FROM materijal_kategorije WHERE id = ?");
            $stmtKat->execute([(int)$input['kategorijaId']]);
            $kat = $stmtKat->fetch();
            if ($kat) {
                $kategorijaNaziv = $kat['naziv'];
            }
        }
        
        $stmt = $db->prepare("UPDATE materijali SET naziv = ?, kategorija = ?, kategorija_id = ?, jedinica_mjere = ?, cijena = ?, min_zaliha = ? WHERE id = ?");
        $stmt->execute([
            $input['naziv'] ?? '',
            $kategorijaNaziv, // Za compatibility
            !empty($input['kategorijaId']) ? (int)$input['kategorijaId'] : null,
            $input['jedinicaMjere'] ?? 'kom',
            $input['cijena'] ?? 0,
            $input['minZaliha'] ?? 0,
            $id
        ]);
        
        sendResponse(['success' => true]);
    }
    
    // DELETE
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("UPDATE materijali SET aktivan = 0 WHERE id = ?");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// ARTIKL MATERIJALI (normativ)
// ============================================
if ($endpoint === 'artikl-materijali') {
    
    // GET by artikl_id
    if ($method === 'GET' && $id) {
        $stmt = $db->prepare("
            SELECT am.*, m.naziv as materijal_naziv, m.jedinica_mjere, m.cijena
            FROM artikl_materijali am
            JOIN materijali m ON m.id = am.materijal_id
            WHERE am.artikl_id = ?
            ORDER BY m.naziv
        ");
        $stmt->execute([$id]);
        $materijali = $stmt->fetchAll();
        
        $result = array_map(function($am) {
            return [
                'id' => (int)$am['id'],
                'materijalId' => (int)$am['materijal_id'],
                'naziv' => $am['materijal_naziv'],
                'jedinicaMjere' => $am['jedinica_mjere'],
                'cijena' => (float)$am['cijena'],
                'kolicina' => (float)$am['kolicina']
            ];
        }, $materijali);
        
        sendResponse($result);
    }
    
    // POST - spremi sve materijale za artikl
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $artiklId = $input['artiklId'] ?? null;
        $materijali = $input['materijali'] ?? [];
        
        if (!$artiklId) sendError('artiklId is required', 400);
        
        // Obriši stare
        $stmt = $db->prepare("DELETE FROM artikl_materijali WHERE artikl_id = ?");
        $stmt->execute([$artiklId]);
        
        // Dodaj nove
        if (!empty($materijali)) {
            $stmt = $db->prepare("INSERT INTO artikl_materijali (artikl_id, materijal_id, kolicina) VALUES (?, ?, ?)");
            foreach ($materijali as $m) {
                if (!empty($m['materijalId'])) {
                    $stmt->execute([
                        $artiklId,
                        $m['materijalId'],
                        $m['kolicina'] ?? 1
                    ]);
                }
            }
        }
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// RASKNJIŽENJE MATERIJALA
// ============================================
if ($endpoint === 'rasknjiži' || $endpoint === 'rasknjizi') {
    
    // POST - raskniži materijale za nalog (prima materijale iz request body)
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $nalogId = $id ?? $input['nalogId'] ?? null;
        if (!$nalogId) sendError('nalogId is required', 400);
        
        // Materijali iz request body
        $materijaliZaRasknjizenje = $input['materijali'] ?? [];
        
        if (empty($materijaliZaRasknjizenje)) {
            sendError('Nema materijala za rasknjiženje', 400);
        }
        
        $ukupnoRasknjizeno = 0;
        $detalji = [];
        
        $db->beginTransaction();
        
        try {
            foreach ($materijaliZaRasknjizenje as $mat) {
                $materijalId = $mat['materijalId'] ?? null;
                $kolicina = (float)($mat['kolicina'] ?? 0);
                $cijena = (float)($mat['cijena'] ?? 0);
                $napomena = $mat['napomena'] ?? '';
                
                if (!$materijalId || $kolicina <= 0) continue;
                
                // Dohvati podatke o materijalu
                $stmt = $db->prepare("SELECT naziv, jedinica_mjere, cijena FROM materijali WHERE id = ?");
                $stmt->execute([$materijalId]);
                $materijalInfo = $stmt->fetch();
                
                if (!$materijalInfo) continue;
                
                // Ako cijena nije poslana, koristi cijenu iz šifarnika
                if ($cijena <= 0) {
                    $cijena = (float)$materijalInfo['cijena'];
                }
                
                // Evidentiraj utrošak
                $stmt = $db->prepare("
                    INSERT INTO nalog_materijali (nalog_id, materijal_id, kolicina, cijena)
                    VALUES (?, ?, ?, ?)
                ");
                $stmt->execute([$nalogId, $materijalId, $kolicina, $cijena]);
                
                // Skini sa zalihe
                $stmt = $db->prepare("UPDATE materijali SET zaliha = zaliha - ? WHERE id = ?");
                $stmt->execute([$kolicina, $materijalId]);
                
                $ukupnoRasknjizeno++;
                $detalji[] = [
                    'materijal' => $materijalInfo['naziv'],
                    'kolicina' => $kolicina,
                    'jedinica' => $materijalInfo['jedinica_mjere'],
                    'vrijednost' => $kolicina * $cijena
                ];
            }
            
            // Označi nalog kao rasknjižen
            $stmt = $db->prepare("UPDATE nalozi SET rasknjizen = 1, rasknjizen_at = NOW() WHERE id = ?");
            $stmt->execute([$nalogId]);
            
            $db->commit();
            
            sendResponse([
                'success' => true,
                'rasknjiženoMaterijala' => $ukupnoRasknjizeno,
                'detalji' => $detalji
            ]);
            
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Greška pri rasknjiženju: ' . $e->getMessage(), 500);
        }
    }
    
    // GET - dohvati utrošene materijale za nalog
    if ($method === 'GET' && $id) {
        $stmt = $db->prepare("
            SELECT nm.*, m.naziv, m.jedinica_mjere
            FROM nalog_materijali nm
            JOIN materijali m ON m.id = nm.materijal_id
            WHERE nm.nalog_id = ?
            ORDER BY m.naziv
        ");
        $stmt->execute([$id]);
        $utroseni = $stmt->fetchAll();
        
        $result = array_map(function($u) {
            return [
                'id' => (int)$u['id'],
                'materijalId' => (int)$u['materijal_id'],
                'naziv' => $u['naziv'],
                'kolicina' => (float)$u['kolicina'],
                'jedinicaMjere' => $u['jedinica_mjere'],
                'cijena' => (float)$u['cijena'],
                'vrijednost' => (float)$u['kolicina'] * (float)$u['cijena']
            ];
        }, $utroseni);
        
        sendResponse($result);
    }
}

// ============================================
// UPRAVLJANJE MATERIJALIMA NA NALOGU (dodavanje, brisanje)
// ============================================
if ($endpoint === 'nalog-materijali') {
    
    // POST - dodaj materijal na nalog
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $nalogId = $input['nalogId'] ?? null;
        $materijalId = $input['materijalId'] ?? null;
        $kolicina = (float)($input['kolicina'] ?? 0);
        $cijena = (float)($input['cijena'] ?? 0);
        
        if (!$nalogId || !$materijalId || $kolicina <= 0) {
            sendError('nalogId, materijalId i kolicina su obavezni', 400);
        }
        
        $db->beginTransaction();
        try {
            // Dohvati podatke o materijalu uključujući trenutnu zalihu
            $stmt = $db->prepare("SELECT cijena, zaliha FROM materijali WHERE id = ?");
            $stmt->execute([$materijalId]);
            $mat = $stmt->fetch();
            if (!$mat) sendError('Materijal nije pronađen', 404);
            
            if ($cijena <= 0) {
                $cijena = (float)$mat['cijena'];
            }
            
            $stanjePrije = (float)$mat['zaliha'];
            $stanjePoslije = $stanjePrije - $kolicina;
            
            // Dodaj u nalog_materijali
            $stmt = $db->prepare("INSERT INTO nalog_materijali (nalog_id, materijal_id, kolicina, cijena, created_by) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$nalogId, $materijalId, $kolicina, $cijena, $userId]);
            $newId = $db->lastInsertId();
            
            // Ažuriraj zalihu
            $stmt = $db->prepare("UPDATE materijali SET zaliha = ? WHERE id = ?");
            $stmt->execute([$stanjePoslije, $materijalId]);
            
            // Spremi IZLAZ knjiženje
            $stmt = $db->prepare("
                INSERT INTO materijal_knjizenja 
                (materijal_id, tip, kolicina, cijena, nalog_id, stanje_prije, stanje_poslije, created_by)
                VALUES (?, 'IZLAZ', ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $materijalId,
                -$kolicina,  // negativno za izlaz
                $cijena,
                $nalogId,
                $stanjePrije,
                $stanjePoslije,
                $userId
            ]);
            
            // Označi nalog kao rasknjižen ako nije
            $stmt = $db->prepare("UPDATE nalozi SET rasknjizen = 1, rasknjizen_at = COALESCE(rasknjizen_at, NOW()) WHERE id = ?");
            $stmt->execute([$nalogId]);
            
            $db->commit();
            sendResponse(['success' => true, 'id' => $newId, 'novaZaliha' => $stanjePoslije]);
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Greška: ' . $e->getMessage(), 500);
        }
    }
    
    // DELETE - storniraj materijal s naloga (ne briše, označava kao storno)
    if ($method === 'DELETE') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $nmId = $id ?? null;
        if (!$nmId) sendError('ID je obavezan', 400);
        
        $db->beginTransaction();
        try {
            // Dohvati podatke o materijalu s artiklom i nalogom
            $stmt = $db->prepare("
                SELECT nm.*, m.zaliha as trenutna_zaliha, 
                       n.broj as nalog_broj,
                       na.naziv as artikl_naziv, na.kolicina as artikl_kolicina, na.jedinica as artikl_jedinica
                FROM nalog_materijali nm 
                JOIN materijali m ON m.id = nm.materijal_id 
                LEFT JOIN nalozi n ON n.id = nm.nalog_id
                LEFT JOIN nalog_artikli na ON na.id = nm.nalog_artikl_id
                WHERE nm.id = ?
            ");
            $stmt->execute([$nmId]);
            $nm = $stmt->fetch();
            
            if (!$nm) sendError('Materijal nije pronađen', 404);
            
            // Provjeri je li već storniran
            if (!empty($nm['stornirano'])) {
                sendError('Materijal je već storniran', 400);
            }
            
            // Izračunaj stanja
            $kolicina = abs((float)$nm['kolicina']);
            $stanjePrije = (float)$nm['trenutna_zaliha'];
            $stanjePoslije = $stanjePrije + $kolicina;
            
            // Vrati na zalihu
            $stmt = $db->prepare("UPDATE materijali SET zaliha = ? WHERE id = ?");
            $stmt->execute([$stanjePoslije, $nm['materijal_id']]);
            
            // Napravi napomenu
            $napomena = 'Storno';
            if (!empty($nm['nalog_broj'])) {
                $napomena .= ' ' . $nm['nalog_broj'];
            }
            if (!empty($nm['artikl_naziv'])) {
                $napomena .= ' / ' . $nm['artikl_naziv'] . ' (' . $nm['artikl_kolicina'] . ' ' . ($nm['artikl_jedinica'] ?: 'kom') . ')';
            }
            
            // Spremi STORNO knjiženje
            $stmt = $db->prepare("
                INSERT INTO materijal_knjizenja 
                (materijal_id, tip, kolicina, cijena, napomena, nalog_id, nalog_artikl_id, stanje_prije, stanje_poslije, created_by)
                VALUES (?, 'STORNO', ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $nm['materijal_id'],
                $kolicina,  // pozitivno za storno (vraća se na zalihu)
                $nm['cijena'],
                $napomena,
                $nm['nalog_id'],
                $nm['nalog_artikl_id'],
                $stanjePrije,
                $stanjePoslije,
                $userId
            ]);
            
            // Označi kao stornirano (ne briši)
            $stmt = $db->prepare("UPDATE nalog_materijali SET stornirano = 1, stornirano_at = NOW(), stornirano_by = ? WHERE id = ?");
            $stmt->execute([$userId, $nmId]);
            
            // Provjeri ima li još aktivnih materijala na nalogu
            $stmt = $db->prepare("SELECT COUNT(*) FROM nalog_materijali WHERE nalog_id = ? AND (stornirano = 0 OR stornirano IS NULL)");
            $stmt->execute([$nm['nalog_id']]);
            $count = $stmt->fetchColumn();
            
            // Ako nema više aktivnih materijala, makni oznaku rasknjiženja
            if ($count == 0) {
                $stmt = $db->prepare("UPDATE nalozi SET rasknjizen = 0, rasknjizen_at = NULL WHERE id = ?");
                $stmt->execute([$nm['nalog_id']]);
            }
            
            $db->commit();
            sendResponse(['success' => true, 'vraćenoNaZalihu' => $kolicina, 'stornirano' => true]);
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Greška: ' . $e->getMessage(), 500);
        }
    }
}

// ============================================
// STORNO RASKNJIŽENJA
// ============================================
if ($endpoint === 'storno-rasknjizi') {
    
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $nalogId = $id ?? $input['nalogId'] ?? null;
        if (!$nalogId) sendError('nalogId is required', 400);
        
        $db->beginTransaction();
        
        try {
            // Dohvati utrošene materijale
            $stmt = $db->prepare("SELECT * FROM nalog_materijali WHERE nalog_id = ?");
            $stmt->execute([$nalogId]);
            $utroseni = $stmt->fetchAll();
            
            // Vrati na zalihu
            foreach ($utroseni as $u) {
                $stmt = $db->prepare("UPDATE materijali SET zaliha = zaliha + ? WHERE id = ?");
                $stmt->execute([$u['kolicina'], $u['materijal_id']]);
            }
            
            // Obriši evidenciju utroška
            $stmt = $db->prepare("DELETE FROM nalog_materijali WHERE nalog_id = ?");
            $stmt->execute([$nalogId]);
            
            // Makni oznaku rasknjiženja
            $stmt = $db->prepare("UPDATE nalozi SET rasknjizen = 0, rasknjizen_at = NULL WHERE id = ?");
            $stmt->execute([$nalogId]);
            
            $db->commit();
            
            sendResponse(['success' => true, 'vraćenoNaZalihu' => count($utroseni)]);
            
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Greška pri stornu: ' . $e->getMessage(), 500);
        }
    }
}

// ============================================
// POVIJEST MATERIJALA - gdje je korišten
// ============================================
if ($endpoint === 'materijal-povijest') {
    
    // GET /materijal-povijest/{materijalId} - povijest rasknjižavanja za materijal
    if ($method === 'GET') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $materijalId = $id ?? null;
        if (!$materijalId) sendError('materijalId is required', 400);
        
        $stmt = $db->prepare("
            SELECT 
                nm.id,
                nm.nalog_id,
                nm.nalog_artikl_id,
                nm.kolicina,
                nm.cijena,
                nm.created_at,
                nm.stornirano,
                nm.stornirano_at,
                n.broj AS nalog_broj,
                n.naziv_naloga AS nalog_naziv,
                n.rasknjizen_at,
                k.naziv AS klijent_naziv,
                na.naziv AS artikl_naziv
            FROM nalog_materijali nm
            JOIN nalozi n ON n.id = nm.nalog_id
            LEFT JOIN kupci k ON k.id = n.klijent_id
            LEFT JOIN nalog_artikli na ON na.id = nm.nalog_artikl_id
            WHERE nm.materijal_id = ?
            ORDER BY nm.created_at DESC
        ");
        $stmt->execute([$materijalId]);
        $povijest = $stmt->fetchAll();
        
        $rezultat = array_map(function($row) {
            $kolicina = abs((float)$row['kolicina']); // Uvijek pozitivna
            $cijena = (float)$row['cijena'];
            return [
                'id' => $row['id'],
                'nalogId' => $row['nalog_id'],
                'nalogBroj' => $row['nalog_broj'],
                'nalogNaziv' => $row['nalog_naziv'],
                'artiklNaziv' => $row['artikl_naziv'],
                'klijent' => $row['klijent_naziv'],
                'kolicina' => $kolicina,
                'cijena' => $cijena,
                'vrijednost' => $kolicina * $cijena,
                'datum' => $row['rasknjizen_at'] ?? $row['created_at'],
                'stornirano' => (bool)$row['stornirano'],
                'storniranoAt' => $row['stornirano_at']
            ];
        }, $povijest);
        
        sendResponse($rezultat);
    }
}

// ============================================
// MATERIJAL KNJIŽENJA - Evidencija promjena zalihe
// ============================================
if ($endpoint === 'materijal-knjizenja') {
    
    // GET /materijal-knjizenja/{materijalId} - sva knjiženja za materijal
    if ($method === 'GET') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $materijalId = $id ?? null;
        if (!$materijalId) sendError('materijalId is required', 400);
        
        $stmt = $db->prepare("
            SELECT 
                mk.*,
                n.broj AS nalog_broj,
                n.naziv_naloga AS nalog_naziv,
                na.naziv AS artikl_naziv,
                na.kolicina AS artikl_kolicina,
                na.jedinica AS artikl_jedinica,
                k.ime AS korisnik_ime,
                kup.naziv AS klijent_naziv
            FROM materijal_knjizenja mk
            LEFT JOIN nalozi n ON n.id = mk.nalog_id
            LEFT JOIN nalog_artikli na ON na.id = mk.nalog_artikl_id
            LEFT JOIN korisnici k ON k.id = mk.created_by
            LEFT JOIN kupci kup ON kup.id = n.klijent_id
            WHERE mk.materijal_id = ?
            ORDER BY mk.created_at DESC, mk.id DESC
        ");
        $stmt->execute([$materijalId]);
        $knjizenja = $stmt->fetchAll();
        
        $rezultat = array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'tip' => $row['tip'],
                'kolicina' => (float)$row['kolicina'],
                'cijena' => (float)$row['cijena'],
                'vrijednost' => abs((float)$row['kolicina']) * (float)$row['cijena'],
                'napomena' => $row['napomena'],
                'nalogId' => $row['nalog_id'],
                'nalogBroj' => $row['nalog_broj'],
                'nalogNaziv' => $row['nalog_naziv'],
                'artiklNaziv' => $row['artikl_naziv'],
                'artiklKolicina' => $row['artikl_kolicina'] ? (float)$row['artikl_kolicina'] : null,
                'artiklJedinica' => $row['artikl_jedinica'],
                'klijent' => $row['klijent_naziv'],
                'stanjePrije' => (float)$row['stanje_prije'],
                'stanjePoslije' => (float)$row['stanje_poslije'],
                'datum' => $row['created_at'],
                'korisnik' => $row['korisnik_ime'] ?: null
            ];
        }, $knjizenja);
        
        sendResponse($rezultat);
    }
    
    // POST /materijal-knjizenja - novo knjiženje (ULAZ, KOREKCIJA ili STORNO)
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $materijalId = $input['materijalId'] ?? null;
        $tip = $input['tip'] ?? null; // ULAZ, KOREKCIJA ili STORNO
        $kolicina = floatval($input['kolicina'] ?? 0);
        $napomena = $input['napomena'] ?? '';
        
        if (!$materijalId) sendError('materijalId je obavezan', 400);
        if (!in_array($tip, ['ULAZ', 'KOREKCIJA', 'STORNO'])) sendError('tip mora biti ULAZ, KOREKCIJA ili STORNO', 400);
        if ($kolicina == 0) sendError('kolicina ne može biti 0', 400);
        
        $db->beginTransaction();
        try {
            // Dohvati trenutno stanje
            $stmt = $db->prepare("SELECT zaliha, cijena FROM materijali WHERE id = ?");
            $stmt->execute([$materijalId]);
            $mat = $stmt->fetch();
            if (!$mat) sendError('Materijal nije pronađen', 404);
            
            $stanjePrije = (float)$mat['zaliha'];
            $stanjePoslije = $stanjePrije + $kolicina;
            
            // Ažuriraj zalihu
            $stmt = $db->prepare("UPDATE materijali SET zaliha = ? WHERE id = ?");
            $stmt->execute([$stanjePoslije, $materijalId]);
            
            // Spremi knjiženje
            $stmt = $db->prepare("
                INSERT INTO materijal_knjizenja 
                (materijal_id, tip, kolicina, cijena, napomena, stanje_prije, stanje_poslije, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $materijalId,
                $tip,
                $kolicina,
                $mat['cijena'],
                $napomena,
                $stanjePrije,
                $stanjePoslije,
                $userId
            ]);
            
            $db->commit();
            sendResponse([
                'success' => true,
                'novaZaliha' => $stanjePoslije,
                'knjizenje' => [
                    'id' => $db->lastInsertId(),
                    'tip' => $tip,
                    'kolicina' => $kolicina,
                    'stanjePrije' => $stanjePrije,
                    'stanjePoslije' => $stanjePoslije
                ]
            ]);
        } catch (Exception $e) {
            $db->rollBack();
            sendError('Greška: ' . $e->getMessage(), 500);
        }
    }
}

// ============================================
// PODSJETNICI - Taskovi vezani na artikle u nalozima
// ============================================
if ($endpoint === 'podsjetnici') {
    
    // GET - dohvati sve podsjetnike (ili za određeni nalog/artikl)
    if ($method === 'GET') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $nalogId = $_GET['nalogId'] ?? null;
        $nalogArtiklId = $_GET['nalogArtiklId'] ?? null;
        
        $sql = "
            SELECT p.*, 
                   n.broj AS nalog_broj,
                   n.naziv_naloga AS nalog_naziv,
                   n.klijent_naziv AS klijent_naziv,
                   COALESCE(p.artikl_naziv, na.naziv) AS artikl_naziv,
                   na.kolicina AS artikl_kolicina,
                   na.jedinica AS artikl_jedinica,
                   k1.ime AS kreirao_ime,
                   k2.ime AS zavrsio_ime
            FROM podsjetnici p
            LEFT JOIN nalozi n ON n.id = p.nalog_id
            LEFT JOIN nalog_artikli na ON na.id = p.nalog_artikl_id
            LEFT JOIN korisnici k1 ON k1.id = p.created_by
            LEFT JOIN korisnici k2 ON k2.id = p.zavrsen_by
            WHERE 1=1
        ";
        $params = [];
        
        if ($nalogArtiklId) {
            $sql .= " AND p.nalog_artikl_id = ?";
            $params[] = $nalogArtiklId;
        } elseif ($nalogId) {
            $sql .= " AND p.nalog_id = ?";
            $params[] = $nalogId;
        }
        
        $sql .= " ORDER BY p.zavrsen ASC, 
                 CASE p.prioritet WHEN 'visok' THEN 1 WHEN 'srednji' THEN 2 ELSE 3 END,
                 p.rok ASC,
                 p.created_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $podsjetnici = $stmt->fetchAll();
        
        $rezultat = array_map(function($row) {
            return [
                'id' => (int)$row['id'],
                'nalogId' => (int)$row['nalog_id'],
                'nalogArtiklId' => $row['nalog_artikl_id'] ? (int)$row['nalog_artikl_id'] : null,
                'nalogBroj' => $row['nalog_broj'],
                'nalogNaziv' => $row['nalog_naziv'],
                'artiklNaziv' => $row['artikl_naziv'],
                'artiklKolicina' => $row['artikl_kolicina'],
                'artiklJedinica' => $row['artikl_jedinica'],
                'klijentNaziv' => $row['klijent_naziv'],
                'tekst' => $row['tekst'],
                'prioritet' => $row['prioritet'],
                'rok' => $row['rok'],
                'zavrsen' => (bool)$row['zavrsen'],
                'createdAt' => $row['created_at'],
                'kreiraoIme' => $row['kreirao_ime'],
                'zavrsioIme' => $row['zavrsio_ime'],
                'zavrsenAt' => $row['zavrsen_at']
            ];
        }, $podsjetnici);
        
        sendResponse($rezultat);
    }
    
    // POST - novi podsjetnik
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['nalogId'])) sendError('nalogId je obavezan', 400);
        
        $nalogId = (int)$input['nalogId'];
        $nalogArtiklId = null;
        $artiklNaziv = null;
        
        // Provjeri nalogArtiklId
        if (isset($input['nalogArtiklId']) && $input['nalogArtiklId'] !== '' && $input['nalogArtiklId'] !== null) {
            $nalogArtiklId = (int)$input['nalogArtiklId'];
            if ($nalogArtiklId === 0) {
                $nalogArtiklId = null;
            } else {
                // Dohvati naziv artikla ODMAH i spremi ga
                $stmt = $db->prepare("SELECT naziv, kolicina, jedinica FROM nalog_artikli WHERE id = ?");
                $stmt->execute([$nalogArtiklId]);
                $artData = $stmt->fetch();
                if ($artData) {
                    $artiklNaziv = $artData['naziv'];
                    if ($artData['kolicina']) {
                        $artiklNaziv .= ' (' . $artData['kolicina'] . ' ' . ($artData['jedinica'] ?: 'kom') . ')';
                    }
                }
            }
        }
        
        $stmt = $db->prepare("
            INSERT INTO podsjetnici (nalog_id, nalog_artikl_id, artikl_naziv, tekst, prioritet, rok, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $nalogId,
            $nalogArtiklId,
            $artiklNaziv,
            $input['tekst'] ?? '',
            $input['prioritet'] ?? 'srednji',
            !empty($input['rok']) ? $input['rok'] : null,
            $userId
        ]);
        
        $newId = $db->lastInsertId();
        
        $stmt = $db->prepare("SELECT broj, naziv_naloga FROM nalozi WHERE id = ?");
        $stmt->execute([$nalogId]);
        $nalogData = $stmt->fetch();
        
        sendResponse([
            'success' => true, 
            'id' => $newId,
            'nalogBroj' => $nalogData['broj'] ?? null,
            'nalogNaziv' => $nalogData['naziv_naloga'] ?? null,
            'artiklNaziv' => $artiklNaziv
        ]);
    }
    
    // PUT - ažuriraj podsjetnik (toggle završen ili uredi tekst)
    if ($method === 'PUT' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Ako je samo toggle završen
        if (isset($input['zavrsen'])) {
            if ($input['zavrsen']) {
                $stmt = $db->prepare("UPDATE podsjetnici SET zavrsen = 1, zavrsen_at = NOW(), zavrsen_by = ? WHERE id = ?");
                $stmt->execute([$userId, $id]);
            } else {
                $stmt = $db->prepare("UPDATE podsjetnici SET zavrsen = 0, zavrsen_at = NULL, zavrsen_by = NULL WHERE id = ?");
                $stmt->execute([$id]);
            }
        } else {
            // Uredi tekst/prioritet/rok
            $stmt = $db->prepare("UPDATE podsjetnici SET tekst = ?, prioritet = ?, rok = ? WHERE id = ?");
            $stmt->execute([
                $input['tekst'] ?? '',
                $input['prioritet'] ?? 'srednji',
                !empty($input['rok']) ? $input['rok'] : null,
                $id
            ]);
        }
        
        sendResponse(['success' => true]);
    }
    
    // DELETE - označi podsjetnik kao završen (soft delete) ili trajno obriši (hard delete za admin)
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Hard delete ako je ?hard=1 i korisnik je admin
        $hardDelete = isset($_GET['hard']) && $_GET['hard'] == '1';
        
        if ($hardDelete) {
            // Provjeri da je admin
            $stmtAdmin = $db->prepare("SELECT uloga FROM korisnici WHERE id = ?");
            $stmtAdmin->execute([$userId]);
            $user = $stmtAdmin->fetch();
            
            if ($user && $user['uloga'] === 'admin') {
                $stmt = $db->prepare("DELETE FROM podsjetnici WHERE id = ?");
                $stmt->execute([$id]);
            } else {
                sendError('Samo admin može trajno obrisati', 403);
            }
        } else {
            // Soft delete - označi kao završen
            $stmt = $db->prepare("UPDATE podsjetnici SET zavrsen = 1, zavrsen_at = NOW(), zavrsen_by = ? WHERE id = ?");
            $stmt->execute([$userId, $id]);
        }
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// KPD 2025 - Pretraživanje šifri
// ============================================
if ($endpoint === 'kpd') {
    
    // GET /kpd?search=tiskanje - pretraživanje
    if ($method === 'GET' && !$id) {
        $search = $_GET['search'] ?? '';
        $limit = min((int)($_GET['limit'] ?? 30), 100);
        
        if (strlen($search) < 1) {
            sendResponse([]);
        }
        
        // Ako počinje brojem, traži po šifri
        if (preg_match('/^[0-9]/', $search)) {
            $stmt = $db->prepare("
                SELECT sifra, naziv, naziv_en, podrucje 
                FROM kpd_2025 
                WHERE sifra LIKE ? 
                ORDER BY sifra 
                LIMIT ?
            ");
            $stmt->execute(["$search%", $limit]);
        } else {
            $stmt = $db->prepare("
                SELECT sifra, naziv, naziv_en, podrucje 
                FROM kpd_2025 
                WHERE naziv LIKE ? OR naziv_en LIKE ?
                ORDER BY 
                    CASE WHEN naziv LIKE ? THEN 0 ELSE 1 END,
                    sifra 
                LIMIT ?
            ");
            $stmt->execute(["%$search%", "%$search%", "$search%", $limit]);
        }
        
        $results = $stmt->fetchAll();
        sendResponse($results);
    }
    
    // GET /kpd/{sifra} - dohvati jednu šifru
    if ($method === 'GET' && $id) {
        // $id je zapravo šifra (npr. "18.12.02"), ali routing prepoznaje samo broj
        // Koristimo query parametar
        $sifra = $_GET['sifra'] ?? '';
        
        if (empty($sifra)) {
            sendError('Sifra parameter required', 400);
        }
        
        $stmt = $db->prepare("SELECT * FROM kpd_2025 WHERE sifra = ?");
        $stmt->execute([$sifra]);
        $result = $stmt->fetch();
        
        if (!$result) {
            sendError('KPD šifra nije pronađena', 404);
        }
        
        sendResponse($result);
    }
}

// ============================================
// KPD PODRUČJA - Lista svih područja
// ============================================
if ($endpoint === 'kpd-podrucja') {
    if ($method === 'GET') {
        $stmt = $db->query("SELECT * FROM kpd_2025_podrucja ORDER BY podrucje");
        sendResponse($stmt->fetchAll());
    }
}

// ============================================
// TASKOVI - Opći podsjetnici
// ============================================
if ($endpoint === 'taskovi') {
    
    // GET - dohvati sve aktivne taskove (nezavršene)
    if ($method === 'GET' && !$id) {
        $stmt = $db->query("
            SELECT t.*, k.ime as kreirao_ime, k.prezime as kreirao_prezime
            FROM taskovi t
            LEFT JOIN korisnici k ON k.id = t.created_by
            WHERE t.zavrsen = 0
            ORDER BY t.created_at DESC
        ");
        $taskovi = $stmt->fetchAll();
        
        $result = array_map(function($t) {
            return [
                'id' => (int)$t['id'],
                'tekst' => $t['tekst'],
                'kreiraoIme' => trim(($t['kreirao_ime'] ?? '') . ' ' . ($t['kreirao_prezime'] ?? '')),
                'createdAt' => $t['created_at']
            ];
        }, $taskovi);
        
        sendResponse($result);
    }
    
    // GET zavrseni - dohvati završene taskove (samo za admina)
    if ($method === 'GET' && $id === 'zavrseni') {
        $stmt = $db->query("
            SELECT t.*, 
                   k1.ime as kreirao_ime, k1.prezime as kreirao_prezime,
                   k2.ime as zavrsio_ime, k2.prezime as zavrsio_prezime
            FROM taskovi t
            LEFT JOIN korisnici k1 ON k1.id = t.created_by
            LEFT JOIN korisnici k2 ON k2.id = t.zavrsen_by
            WHERE t.zavrsen = 1
            ORDER BY t.zavrsen_at DESC
        ");
        $taskovi = $stmt->fetchAll();
        
        $result = array_map(function($t) {
            return [
                'id' => (int)$t['id'],
                'tekst' => $t['tekst'],
                'kreiraoIme' => trim(($t['kreirao_ime'] ?? '') . ' ' . ($t['kreirao_prezime'] ?? '')),
                'createdAt' => $t['created_at'],
                'zavrsioIme' => trim(($t['zavrsio_ime'] ?? '') . ' ' . ($t['zavrsio_prezime'] ?? '')),
                'zavrsenAt' => $t['zavrsen_at']
            ];
        }, $taskovi);
        
        sendResponse($result);
    }
    
    // POST - novi task
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $tekst = trim($input['tekst'] ?? '');
        if (empty($tekst)) sendError('Tekst je obavezan', 400);
        
        $stmt = $db->prepare("INSERT INTO taskovi (tekst, created_by) VALUES (?, ?)");
        $stmt->execute([$tekst, $userId]);
        
        $newId = $db->lastInsertId();
        
        // Dohvati ime kreatora
        $stmtUser = $db->prepare("SELECT ime, prezime FROM korisnici WHERE id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch();
        
        sendResponse([
            'success' => true,
            'id' => (int)$newId,
            'tekst' => $tekst,
            'kreiraoIme' => trim(($user['ime'] ?? '') . ' ' . ($user['prezime'] ?? '')),
            'createdAt' => date('Y-m-d H:i:s')
        ]);
    }
    
    // PUT - uredi task
    if ($method === 'PUT' && $id && $id !== 'zavrseni') {
        if (!$userId) sendError('Unauthorized', 401);
        
        $tekst = trim($input['tekst'] ?? '');
        if (empty($tekst)) sendError('Tekst je obavezan', 400);
        
        $stmt = $db->prepare("UPDATE taskovi SET tekst = ? WHERE id = ?");
        $stmt->execute([$tekst, $id]);
        
        sendResponse(['success' => true]);
    }
    
    // DELETE - označi kao završen ili trajno obriši
    if ($method === 'DELETE' && $id && $id !== 'zavrseni') {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Provjeri da li je trajno brisanje (admin briše završeni task)
        $permanent = isset($_GET['permanent']) && $_GET['permanent'] === '1';
        
        if ($permanent) {
            $stmt = $db->prepare("DELETE FROM taskovi WHERE id = ?");
            $stmt->execute([$id]);
        } else {
            $stmt = $db->prepare("UPDATE taskovi SET zavrsen = 1, zavrsen_by = ?, zavrsen_at = NOW() WHERE id = ?");
            $stmt->execute([$userId, $id]);
        }
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// OTPREMNICA - Označi izdanu otpremnicu
// ============================================
if ($endpoint === 'otpremnica') {
    
    // POST - označi otpremnicu izdanom
    if ($method === 'POST' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        // Generiraj broj otpremnice (OTP-YYYY-NNN)
        $year = date('Y');
        $stmtMax = $db->prepare("SELECT MAX(CAST(SUBSTRING(otpremnica_broj, 10) AS UNSIGNED)) as max_num FROM nalozi WHERE otpremnica_broj LIKE ?");
        $stmtMax->execute(["OTP-$year-%"]);
        $result = $stmtMax->fetch();
        $nextNum = ($result['max_num'] ?? 0) + 1;
        $otpremnicaBroj = sprintf("OTP-%s-%03d", $year, $nextNum);
        
        $stmt = $db->prepare("
            UPDATE nalozi 
            SET otpremnica_izdana = 1,
                otpremnica_broj = ?,
                otpremnica_datum = NOW(), 
                otpremnica_izdao = ? 
            WHERE id = ?
        ");
        $stmt->execute([$otpremnicaBroj, $userId, $id]);
        
        // Dohvati ime operatera
        $stmtUser = $db->prepare("SELECT ime, prezime FROM korisnici WHERE id = ?");
        $stmtUser->execute([$userId]);
        $user = $stmtUser->fetch();
        
        sendResponse([
            'success' => true,
            'otpremnicaIzdana' => true,
            'otpremnicaBroj' => $otpremnicaBroj,
            'otpremnicaDatum' => date('Y-m-d H:i:s'),
            'otpremnicaIzdaoIme' => trim(($user['ime'] ?? '') . ' ' . ($user['prezime'] ?? ''))
        ]);
    }
    
    // DELETE - poništi otpremnicu (samo admin)
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);
        
        $stmt = $db->prepare("
            UPDATE nalozi 
            SET otpremnica_izdana = 0, 
                otpremnica_datum = NULL, 
                otpremnica_izdao = NULL 
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        
        sendResponse(['success' => true]);
    }
}

// ============================================
// ATTACHMENTI - Fotografije/dokumenti uz naloge
// ============================================
if ($endpoint === 'attachments') {

    // Kreiraj tablicu ako ne postoji
    $db->exec("CREATE TABLE IF NOT EXISTS nalog_attachments (
        id INT PRIMARY KEY AUTO_INCREMENT,
        nalog_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) NOT NULL,
        file_path VARCHAR(500) NOT NULL,
        file_size INT,
        mime_type VARCHAR(100),
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        deleted_at TIMESTAMP NULL
    )");

    // POST - upload attachment
    if ($method === 'POST') {
        if (!$userId) sendError('Unauthorized', 401);

        $nalogId = $_POST['nalog_id'] ?? null;
        if (!$nalogId) sendError('nalog_id je obavezan', 400);

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            sendError('Greška pri uploadu fajla', 400);
        }

        $file = $_FILES['file'];
        $originalName = $file['name'];
        $tmpPath = $file['tmp_name'];
        $mimeType = $file['type'];
        $fileSize = $file['size'];

        // Dozvoljeni tipovi
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'pdf'];

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if (!in_array($mimeType, $allowedTypes) || !in_array($extension, $allowedExtensions)) {
            sendError('Dozvoljeni su samo JPG, PNG, GIF i PDF fajlovi', 400);
        }

        // Upload folder
        $uploadDir = __DIR__ . '/uploads/nalozi/' . $nalogId;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // Generiraj jedinstveno ime
        $uniqueName = time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
        $targetPath = $uploadDir . '/' . $uniqueName;
        $relativePath = 'uploads/nalozi/' . $nalogId . '/' . $uniqueName;

        // Ako je slika, komprimiraj na max 1MB
        $maxSize = 1024 * 1024; // 1MB

        if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) {
            // Učitaj sliku
            $image = null;
            if ($extension === 'png') {
                $image = @imagecreatefrompng($tmpPath);
            } elseif ($extension === 'gif') {
                $image = @imagecreatefromgif($tmpPath);
            } else {
                $image = @imagecreatefromjpeg($tmpPath);
            }

            if ($image) {
                $width = imagesx($image);
                $height = imagesy($image);

                // Ako je fajl prevelik, smanji dimenzije
                $quality = 85;
                $maxDimension = 2000;

                // Smanji ako je veća od maxDimension
                if ($width > $maxDimension || $height > $maxDimension) {
                    $ratio = min($maxDimension / $width, $maxDimension / $height);
                    $newWidth = (int)($width * $ratio);
                    $newHeight = (int)($height * $ratio);

                    $resized = imagecreatetruecolor($newWidth, $newHeight);

                    // Očuvaj transparentnost za PNG
                    if ($extension === 'png') {
                        imagealphablending($resized, false);
                        imagesavealpha($resized, true);
                    }

                    imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
                    imagedestroy($image);
                    $image = $resized;
                }

                // Spremi komprimiranu sliku
                if ($extension === 'png') {
                    imagepng($image, $targetPath, 6); // Kompresija 0-9
                } elseif ($extension === 'gif') {
                    imagegif($image, $targetPath);
                } else {
                    // Za JPEG, iterativno smanji kvalitetu dok nije ispod 1MB
                    imagejpeg($image, $targetPath, $quality);

                    while (filesize($targetPath) > $maxSize && $quality > 20) {
                        $quality -= 10;
                        imagejpeg($image, $targetPath, $quality);
                    }
                }

                imagedestroy($image);
                $fileSize = filesize($targetPath);
            } else {
                // Ako ne može učitati kao sliku, samo kopiraj
                move_uploaded_file($tmpPath, $targetPath);
            }
        } else {
            // PDF - samo kopiraj, ali provjeri veličinu
            if ($fileSize > $maxSize * 10) { // Max 10MB za PDF
                sendError('PDF fajl je prevelik (max 10MB)', 400);
            }
            move_uploaded_file($tmpPath, $targetPath);
        }

        // Spremi u bazu
        $stmt = $db->prepare("
            INSERT INTO nalog_attachments (nalog_id, filename, original_filename, file_path, file_size, mime_type, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $nalogId,
            $uniqueName,
            $originalName,
            $relativePath,
            $fileSize,
            $mimeType,
            $userId
        ]);

        $newId = $db->lastInsertId();

        sendResponse([
            'success' => true,
            'id' => (int)$newId,
            'filename' => $uniqueName,
            'originalFilename' => $originalName,
            'filePath' => $relativePath,
            'fileSize' => $fileSize,
            'mimeType' => $mimeType
        ]);
    }

    // DELETE - soft delete attachment
    if ($method === 'DELETE' && $id) {
        if (!$userId) sendError('Unauthorized', 401);

        $stmt = $db->prepare("UPDATE nalog_attachments SET deleted_at = NOW() WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);

        sendResponse(['success' => true]);
    }
}

// Endpoint nije pronađen
sendError('Endpoint not found: ' . $endpoint, 404);
?>
