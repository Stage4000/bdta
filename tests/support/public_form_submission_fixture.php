<?php
require_once __DIR__ . '/survey_test_bootstrap.php';
require_once dirname(__DIR__, 2) . '/backend/includes/survey_results.php';
$case = scalar_string($argv[1] ?? '');

/** SQLite adapter only removes MySQL row-lock syntax; all writes execute real SQL. */
class PublicFormFixturePDO extends SafePDO
{
    public string $scenario = '';
    public int $workflow_checks = 0;

    /** @param array<mixed> $options */
    public function prepare(string $query, array $options = []): SafePDOStatement
    {
        if (str_contains($query, 'FROM workflow_triggers')) {
            $this->workflow_checks++;
        }
        return parent::prepare($this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? str_replace('FOR UPDATE', '', $query) : $query, $options);
    }

    public function beginTransaction(): bool
    {
        // Simulate a request that loaded pending state before another writer completed.
        switch ($this->scenario) {
            case 'stale-pending':
                $this->exec("UPDATE form_submissions SET status = 'submitted', responses = '{\"0\":\"Winner\"}' WHERE id = 101");
                break;
            case 'changed-client':
                $this->exec('UPDATE form_submissions SET client_id = 22 WHERE id = 101');
                break;
            case 'changed-template':
                $this->exec('UPDATE form_submissions SET template_id = 2 WHERE id = 101');
                break;
            case 'changed-token':
                $this->exec("UPDATE form_submissions SET access_token = 'rotated-fixture-token' WHERE id = 101");
                break;
            case 'changed-booking':
                $this->exec('UPDATE form_submissions SET booking_id = 302 WHERE id = 101');
                break;
            case 'changed-pet':
                $this->exec('UPDATE form_submissions SET pet_id = 402 WHERE id = 101');
                break;
            case 'changed-fields':
                $this->exec("UPDATE form_templates SET fields = '[]' WHERE id = 1");
                break;
        }
        return parent::beginTransaction();
    }
}

class PublicFormFixtureStatement extends SafePDOStatement
{
    /** @param array<mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        if ($params === null) {
            return parent::execute();
        }
        // SQLite expressions do not apply MySQL's numeric coercion to string-bound ints.
        foreach (array_values($params) as $index => $value) {
            $this->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
        }
        return parent::execute();
    }
}

$mysql_fixture = getenv('BDTA_SURVEY_TEST_MYSQL') === '1';
if ($mysql_fixture) {
    // Opt-in integration runs must name a dedicated disposable loopback schema.
    $fixture_database = scalar_string(getenv('DB_NAME'));
    if (getenv('DB_HOST') !== '127.0.0.1' || preg_match('/^bdta_survey_controller_[a-z0-9_]+$/', $fixture_database) !== 1) {
        throw new RuntimeException('MySQL controller tests require a dedicated synthetic loopback database.');
    }
    $conn = new PublicFormFixturePDO(
        'mysql:host=127.0.0.1;port=' . scalar_string(getenv('DB_PORT')) . ';dbname=' . $fixture_database . ';charset=utf8mb4',
        scalar_string(getenv('DB_USER')), scalar_string(getenv('DB_PASSWORD'))
    );
} else {
    $conn = new PublicFormFixturePDO('sqlite::memory:');
}
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_STATEMENT_CLASS, [PublicFormFixtureStatement::class]);
$conn->exec('CREATE TABLE settings (setting_key TEXT, setting_value TEXT, setting_type TEXT)');
$primary_key = $mysql_fixture ? 'INTEGER PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY';
$conn->exec('CREATE TABLE clients (id ' . $primary_key . ', name TEXT, email TEXT, phone TEXT, created_at TEXT, updated_at TEXT)');
$conn->exec('CREATE TABLE form_templates (id INTEGER PRIMARY KEY, name TEXT, description TEXT, fields TEXT, form_type TEXT, is_active INTEGER, is_internal INTEGER)');
$conn->exec('CREATE TABLE form_submissions (id ' . $primary_key . ', client_id INTEGER NOT NULL, template_id INTEGER NOT NULL, booking_id INTEGER, pet_id INTEGER, access_token TEXT, responses TEXT, status TEXT, submitted_at TEXT, submitted_by INTEGER)');
$conn->exec('CREATE TABLE pets (id INTEGER PRIMARY KEY, client_id INTEGER, name TEXT, species TEXT, breed TEXT, date_of_birth TEXT, age_years INTEGER, age_months INTEGER, source TEXT, ownership_length_years INTEGER, ownership_length_months INTEGER, spayed_neutered INTEGER, vaccines_current INTEGER, is_active INTEGER, created_at TEXT, updated_at TEXT)');
$conn->exec('CREATE TABLE workflow_triggers (workflow_id INTEGER, trigger_type TEXT, form_template_id INTEGER, is_active INTEGER)');
$conn->exec("INSERT INTO clients VALUES (21, 'Alice Fixture', 'alice@example.invalid', '111', NULL, NULL), (22, 'Bob Fixture', 'bob@example.invalid', '222', NULL, NULL)");
$fields = [
    ['type' => 'radio', 'label' => 'Satisfaction', 'required' => 1, 'options' => ['Good', 'Poor']],
    ['type' => 'text', 'label' => 'Comments', 'required' => 1],
];
if ($case === 'rollback-pet') {
    $fields[] = ['type' => 'pet_info_group', 'label' => 'Pets'];
    $conn->exec("INSERT INTO pets (id, client_id, name, species, breed, is_active) VALUES (401, 21, 'Fixture Dog', 'Dog', 'Original breed', 1)");
    $conn->exec($mysql_fixture
        ? "CREATE TRIGGER fail_pet_update BEFORE UPDATE ON pets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic pet failure'"
        : "CREATE TRIGGER fail_pet_update BEFORE UPDATE ON pets BEGIN SELECT RAISE(ABORT, 'Synthetic pet failure'); END");
}
$stmt = $conn->prepare("INSERT INTO form_templates VALUES (?, ?, '', ?, 'survey_form', 1, 0)");
$stmt->execute([1, 'Survey A', json_encode($fields)]);
$stmt->execute([2, 'Survey B', json_encode([['type' => 'text', 'label' => 'Favorite service', 'required' => 1]])]);
$stmt = $conn->prepare("INSERT INTO form_submissions VALUES (?, ?, ?, ?, ?, ?, '{}', 'pending', NULL, NULL)");
$stmt->execute([101, 21, 1, 301, 401, 'synthetic-invitation-a']);
$stmt->execute([102, 22, 2, 302, 402, 'synthetic-invitation-b']);
$conn->scenario = $case;
if ($case === 'repeated') {
    $conn->exec("UPDATE form_submissions SET status = 'submitted', responses = '{\"0\":\"Original\"}' WHERE id = 101");
}
if ($case === 'rollback') {
    $conn->exec($mysql_fixture
        ? "CREATE TRIGGER fail_contact_update BEFORE UPDATE ON clients FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Synthetic contact failure'"
        : "CREATE TRIGGER fail_contact_update BEFORE UPDATE ON clients BEGIN SELECT RAISE(ABORT, 'Synthetic contact failure'); END");
}
$before_submissions = $conn->query('SELECT * FROM form_submissions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$before_clients = $conn->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$before_pets = $conn->query('SELECT * FROM pets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
(new ReflectionProperty(Database::class, 'sharedConnection'))->setValue(null, $conn);

$_SESSION = ['csrf_token' => 'synthetic-csrf'];
$_GET = ['token' => 'synthetic-invitation-a'];
$_POST = [
    'csrf_token' => 'synthetic-csrf', 'submission_id' => 101, 'template_id' => 1,
    'client_id' => 22, 'booking_id' => 302,
    'contact_name' => 'Alice Updated', 'contact_email' => 'alice@example.invalid',
    'contact_phone' => '333', 'field' => ['Good', 'Comment'],
];
if ($case === 'rollback-pet') {
    $_POST['field'][2] = [['name' => 'Fixture Dog', 'breed' => 'Changed breed', 'age_or_dob' => '2 years',
        'vaccines_current' => 'yes', 'spayed_neutered' => 'yes', 'source' => 'Rescue', 'ownership_length' => '1 year']];
}
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REQUEST_URI'] = '/backend/public/form.php';
$_SERVER['SCRIPT_NAME'] = '/backend/public/form.php';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
if (in_array($case, ['valid-direct', 'direct-forged-template', 'direct-forged-submission'], true)) {
    $_GET = ['template_id' => 1];
    unset($_POST['submission_id']);
}
switch ($case) {
    case 'cross-form':
    case 'direct-forged-submission': $_POST['submission_id'] = 102; break;
    case 'forged-template':
    case 'direct-forged-template': $_POST['template_id'] = 2; break;
    case 'invitation-downgrade': $_POST['submission_id'] = 0; break;
    case 'portal-other-invite': $_SESSION['portal_client_id'] = 22; break;
    case 'admin-id': $_SESSION['admin_id'] = 9; $_SESSION['admin_logged_in'] = true; $_GET = ['id' => 101]; break;
    case 'owner-id': $_SESSION['portal_client_id'] = 21; $_GET = ['id' => 101]; break;
    case 'anonymous-id': $_GET = ['id' => 101]; break;
}

$valid = in_array($case, ['valid-invited', 'valid-direct', 'portal-other-invite', 'admin-id', 'owner-id'], true);
ob_start();
register_shutdown_function(static function () use ($conn, $case, $valid, $before_submissions, $before_clients, $before_pets, $fields): void {
    $html = scalar_string(ob_get_clean());
    $failures = [];
    $check = static function (bool $ok, string $message) use (&$failures): void {
        if (!$ok) { $failures[] = $message; }
    };
    $after = $conn->query('SELECT * FROM form_submissions ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $clients = $conn->query('SELECT * FROM clients ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $success = str_contains($html, 'Thank you! Your form has been submitted successfully.');
    $check($success === $valid, 'submission outcome');
    $check(!$conn->inTransaction(), 'no transaction left open');
    $check($after[1] === $before_submissions[1], 'other invitation remains untouched');
    $check($clients[1] === $before_clients[1], 'other client remains untouched');
    if ($valid) {
        $saved = $case === 'valid-direct' ? ($after[2] ?? []) : $after[0];
        $check(array_int_value($saved, 'client_id') === 21, 'authoritative client linkage');
        $check(array_int_value($saved, 'template_id') === 1 && array_string_value($saved, 'responses') === '["Good","Comment"]', 'answers bound to selected template');
        $check(array_string_value($saved, 'status') === 'submitted', 'completed status');
        if ($case !== 'valid-direct') {
            $check(array_int_value($saved, 'booking_id') === 301 && array_int_value($saved, 'pet_id') === 401, 'invitation context retained');
        }
        $check($conn->workflow_checks === 1, 'one workflow check for winning submission');
        $results = bdta_build_survey_results($fields, [$saved, $after[1]]);
        $check($results['total_submissions'] === 1, 'analytics excludes pending invitation');
        $rating = assoc_row($results['fields'][0] ?? []);
        $good_option = assoc_rows($rating['options'] ?? [])[0] ?? [];
        $comments = assoc_row($results['fields'][1] ?? []);
        $comment = assoc_rows($comments['recent_responses'] ?? [])[0] ?? [];
        $check(array_string_value($rating, 'label') === 'Satisfaction'
            && array_int_value($good_option, 'count') === 1 && array_int_value($good_option, 'percentage') === 100
            && array_string_value($comments, 'label') === 'Comments' && array_string_value($comment, 'value') === 'Comment',
            'analytics preserves positional question meanings and answer counts');
        $check(count($after) === ($case === 'valid-direct' ? 3 : 2), 'no duplicate response');
    } else {
        $check($clients === $before_clients, 'rejected request has no profile writes');
        $check($conn->query('SELECT * FROM pets ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) === $before_pets, 'rejected request has no pet profile writes');
        $check($conn->workflow_checks === 0, 'rejected request has no workflow check');
        $check(count($after) === 2, 'rejected request creates no response');
        if (in_array($case, ['changed-client', 'changed-template', 'changed-token', 'changed-booking', 'changed-pet'], true)) {
            $check(array_string_value($after[0], 'status') === 'pending' && array_string_value($after[0], 'responses') === '{}', 'stale identity has no answer writes');
        } elseif ($case === 'stale-pending') {
            $check(array_string_value($after[0], 'responses') === '{"0":"Winner"}', 'repeat keeps winning answers');
        } else {
            $check($after[0] === $before_submissions[0], 'rejection preserves authorized response');
        }
    }
    $check(error_get_last() === null, 'no unhandled controller error');
    echo ($failures === [] ? 'PASS' : 'FAIL') . ': ' . $case . ($failures === [] ? '' : ' (' . implode('; ', $failures) . ')') . "\n";
    exit($failures === [] ? 0 : 1);
});
chdir(dirname(__DIR__, 2) . '/backend/public');
require dirname(__DIR__, 2) . '/backend/public/form.php';
