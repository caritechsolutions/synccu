<?php
/**
 * Import members from REPORT_CORRECTED.csv into the members table.
 *
 * Usage (run from project root as a user with DB access):
 *   php import_members.php
 *
 * CSV columns used (Corrections_Made is skipped):
 *   Account_No, Name, ID, Addr_Line1, Addr_Line2, Joint_Owner_SS,
 *   Birth_Date, Date_Joined, Email_Misc2, Phone_Misc3, Phone_Misc4
 *
 * Column mapping to members table:
 *   Account_No      → member_number
 *   Name            → last_name, first_name  (split on first comma)
 *   ID              → ssn_last4              (last 4 digits extracted)
 *   Addr_Line1      → address
 *   Addr_Line2      → city
 *   Birth_Date      → date_of_birth          (MM/DD/YYYY → YYYY-MM-DD)
 *   Date_Joined     → date_joined            (MM/DD/YYYY → YYYY-MM-DD)
 *   Email_Misc2     → email                  (validated; invalid value stored in notes)
 *   Phone_Misc3     → phone                  (unless it completes a split email)
 *   Joint_Owner_SS  → notes
 *   Phone_Misc4     → notes
 */

// ── Load .env ─────────────────────────────────────────────────────────────────
$envFile = __DIR__ . '/web/.env';
if (!file_exists($envFile)) {
    die("[ERROR] .env file not found at {$envFile}. Run install.sh first.\n");
}

foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if (str_starts_with($line, '#') || !str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $_ENV[trim($k)] = trim($v);
}

$dbHost = $_ENV['DB_HOST'] ?? 'localhost';
$dbName = $_ENV['DB_NAME'] ?? 'synccu';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("[ERROR] Database connection failed: " . $e->getMessage() . "\n");
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Convert MM/DD/YYYY → YYYY-MM-DD. Returns null if blank or unparseable.
 */
function parseDate(string $val): ?string
{
    $val = trim($val);
    if ($val === '') {
        return null;
    }
    if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $val, $m)) {
        $y = (int)$m[3];
        $mo = (int)$m[1];
        $d  = (int)$m[2];
        if ($y >= 1900 && $y <= 2100 && checkdate($mo, $d, $y)) {
            return sprintf('%04d-%02d-%02d', $y, $mo, $d);
        }
    }
    return null; // unparseable — will be stored in notes below
}

/**
 * Extract last 4 digits from an ID string (e.g. "520123-0066" → "0066").
 * Returns null if there are fewer than 4 digits.
 */
function extractSsnLast4(string $val): ?string
{
    $digits = preg_replace('/\D/', '', trim($val));
    return strlen($digits) >= 4 ? substr($digits, -4) : null;
}

/**
 * Return true if $val looks like a valid e-mail address.
 */
function isEmail(string $val): bool
{
    return filter_var($val, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Return true if $val looks like a phone number / numeric string
 * rather than something meaningful as a standalone note.
 */
function looksLikePhone(string $val): bool
{
    // Mostly digits, dashes, slashes, spaces, plus — not words
    return preg_match('/^[\d\s\-\/\+\.()]+$/', $val) === 1;
}

// ── Prepare INSERT statement ───────────────────────────────────────────────────
$stmt = $pdo->prepare("
    INSERT INTO members
        (member_number, first_name, last_name, ssn_last4,
         address, city, date_of_birth, date_joined,
         email, phone, notes)
    VALUES
        (:member_number, :first_name, :last_name, :ssn_last4,
         :address, :city, :date_of_birth, :date_joined,
         :email, :phone, :notes)
    ON DUPLICATE KEY UPDATE
        first_name    = VALUES(first_name),
        last_name     = VALUES(last_name),
        ssn_last4     = COALESCE(VALUES(ssn_last4), ssn_last4),
        address       = COALESCE(VALUES(address),   address),
        city          = COALESCE(VALUES(city),       city),
        date_of_birth = COALESCE(VALUES(date_of_birth), date_of_birth),
        date_joined   = VALUES(date_joined),
        email         = COALESCE(VALUES(email),      email),
        phone         = COALESCE(VALUES(phone),      phone),
        notes         = COALESCE(VALUES(notes),      notes)
");

// ── Open CSV ──────────────────────────────────────────────────────────────────
$csvFile = __DIR__ . '/REPORT_CORRECTED.csv';
if (!file_exists($csvFile)) {
    die("[ERROR] CSV not found: {$csvFile}\n");
}

$handle = fopen($csvFile, 'r');
fgetcsv($handle); // skip header row

$inserted = 0;
$updated  = 0;
$skipped  = 0;
$lineNum  = 1;

// ── Process rows ──────────────────────────────────────────────────────────────
while (($row = fgetcsv($handle)) !== false) {
    $lineNum++;

    // Pad to expected column count in case trailing empty fields were stripped
    while (count($row) < 12) {
        $row[] = '';
    }

    [
        $accountNo,  $name,      $id,       $addr1,
        $addr2,      $jointOwner,$birthDate,$dateJoined,
        $emailMisc,  $phone1,    $phone2    // $corrections index 11 is ignored
    ] = $row;

    // ── member_number ─────────────────────────────────────────────────────────
    $memberNumber = trim($accountNo);
    if ($memberNumber === '') {
        echo "[WARN] Line {$lineNum}: empty Account_No — skipping.\n";
        $skipped++;
        continue;
    }

    // ── name → last_name / first_name ─────────────────────────────────────────
    $rawName  = trim($name);
    $commaPos = strpos($rawName, ',');
    if ($commaPos !== false) {
        $lastName  = trim(substr($rawName, 0, $commaPos));
        $firstName = trim(substr($rawName, $commaPos + 1));
    } else {
        // No comma — treat entire value as first name
        $firstName = $rawName;
        $lastName  = '';
    }

    // Guard: skip rows where we cannot derive any usable name
    if ($firstName === '' && $lastName === '') {
        echo "[WARN] Line {$lineNum}: Account #{$memberNumber} has no usable name — skipping.\n";
        $skipped++;
        continue;
    }

    // ── ssn_last4 ─────────────────────────────────────────────────────────────
    $ssnLast4 = extractSsnLast4($id);

    // ── dates ─────────────────────────────────────────────────────────────────
    $dob    = parseDate($birthDate);
    $joined = parseDate($dateJoined);

    // date_joined is NOT NULL — fall back to today if blank/unparseable
    if ($joined === null) {
        $joined = date('Y-m-d');
    }

    // ── email resolution (handles split emails across Email_Misc2 / Phone_Misc3) ──
    $emailVal = trim($emailMisc);
    $phone1Val = trim($phone1);
    $email     = null;
    $emailNote = null; // non-email text from Email_Misc2 that goes to notes

    if ($emailVal !== '') {
        if (isEmail($emailVal)) {
            // Clean valid email
            $email = strtolower($emailVal);
        } elseif (str_contains($emailVal, '@') && $phone1Val !== '') {
            // Possibly a split email — try concatenating with Phone_Misc3
            $combined = $emailVal . $phone1Val;
            if (isEmail($combined)) {
                $email     = strtolower($combined);
                $phone1Val = ''; // Phone_Misc3 was actually the email tail
            } else {
                // Not recoverable as email
                $emailNote = $emailVal;
            }
        } else {
            // Not an email (e.g. FEMALE, MALE, a name, a phone number)
            $emailNote = $emailVal;
        }
    }

    // ── phone ─────────────────────────────────────────────────────────────────
    $phone = $phone1Val !== '' ? $phone1Val : null;

    // ── notes (combine joint owner, non-email misc, secondary phone) ──────────
    $noteParts = [];
    $jointVal  = trim($jointOwner);
    $phone2Val = trim($phone2);

    if ($jointVal !== '') {
        $noteParts[] = "Joint Owner: {$jointVal}";
    }
    if ($emailNote !== null && $emailNote !== '') {
        // Only add to notes if it's not just a gender marker handled elsewhere
        $noteParts[] = "Misc: {$emailNote}";
    }
    if ($phone2Val !== '') {
        $noteParts[] = "Phone 2: {$phone2Val}";
    }

    $notes = $noteParts ? implode("\n", $noteParts) : null;

    // ── insert / update ───────────────────────────────────────────────────────
    try {
        $existsBefore = (int)$pdo
            ->query("SELECT COUNT(*) FROM members WHERE member_number = " . $pdo->quote($memberNumber))
            ->fetchColumn();

        $stmt->execute([
            ':member_number' => $memberNumber,
            ':first_name'    => $firstName,
            ':last_name'     => $lastName,
            ':ssn_last4'     => $ssnLast4,
            ':address'       => $addr1 !== '' ? $addr1 : null,
            ':city'          => $addr2 !== '' ? $addr2 : null,
            ':date_of_birth' => $dob,
            ':date_joined'   => $joined,
            ':email'         => $email,
            ':phone'         => $phone,
            ':notes'         => $notes,
        ]);

        if ($existsBefore === 0) {
            $inserted++;
        } else {
            $updated++;
        }
    } catch (PDOException $e) {
        echo "[ERROR] Line {$lineNum} (Account #{$memberNumber}): " . $e->getMessage() . "\n";
        $skipped++;
    }
}

fclose($handle);

// ── Summary ───────────────────────────────────────────────────────────────────
$total = $inserted + $updated + $skipped;
echo "\n";
echo "╔══════════════════════════════════════╗\n";
echo "║     Import Complete                  ║\n";
echo "╚══════════════════════════════════════╝\n";
echo "\n";
echo "  CSV file  : REPORT_CORRECTED.csv\n";
echo "  Rows read : {$total}\n";
echo "  Inserted  : {$inserted}\n";
echo "  Updated   : {$updated}\n";
echo "  Skipped   : {$skipped}\n";
echo "\n";
