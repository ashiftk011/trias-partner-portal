<?php
/**
 * Meta (Facebook / Instagram) Lead Integration Service
 * 
 * Implements Facebook Graph API v21.0 integration for Lead Ads:
 * - Page Access Token validation & Page information
 * - Listing Leadgen Forms for Facebook Pages
 * - Fetching leads from Lead Forms with pagination
 * - Webhook event payload processing (leadgen_id retrieval)
 * - Automatic DB deduplication & field mapping
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/auth.php'; // For generateCode()

class MetaLeadService {
    private PDO $db;
    private string $accessToken = '';
    private string $pageId = '';
    private string $verifyToken = '';
    private int $defaultProjectId = 0;
    private int $defaultAssignedTo = 0;
    private string $graphApiVersion = 'v21.0';

    public function __construct(?PDO $db = null) {
        $this->db = $db ?? getDB();
        $this->ensureDatabaseSchema();
        $this->loadSettings();
    }

    /**
     * Auto-ensure meta_lead_id column exists in leads table
     */
    private function ensureDatabaseSchema(): void {
        try {
            $check = $this->db->query("SHOW COLUMNS FROM leads LIKE 'meta_lead_id'");
            if ($check->rowCount() === 0) {
                $this->db->exec("ALTER TABLE leads ADD COLUMN meta_lead_id VARCHAR(100) NULL UNIQUE AFTER lead_code");
            }
        } catch (Exception $e) {
            // Silently ignore if already present or missing privilege
        }
    }

    /**
     * Load settings from app_settings table
     */
    public function loadSettings(): void {
        try {
            $stmt = $this->db->query("SELECT setting_key, setting_value FROM app_settings WHERE setting_key LIKE 'meta_%'");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

            $this->accessToken = trim($settings['meta_access_token'] ?? '');
            $this->pageId = trim($settings['meta_page_id'] ?? '');
            $this->verifyToken = trim($settings['meta_verify_token'] ?? 'trias_meta_lead_verify_token');
            $this->defaultProjectId = (int)($settings['meta_default_project_id'] ?? 0);
            $this->defaultAssignedTo = (int)($settings['meta_default_assigned_to'] ?? 0);

            if ($this->defaultProjectId <= 0) {
                $p = $this->db->query("SELECT id FROM projects WHERE status='active' ORDER BY id ASC LIMIT 1")->fetchColumn();
                if ($p) $this->defaultProjectId = (int)$p;
            }
        } catch (Exception $e) {
            // Settings load error fallback
        }
    }

    /**
     * Save settings to DB
     */
    public function saveSettings(array $data): void {
        $fields = [
            'meta_access_token' => trim($data['meta_access_token'] ?? ''),
            'meta_page_id' => trim($data['meta_page_id'] ?? ''),
            'meta_verify_token' => trim($data['meta_verify_token'] ?? 'trias_meta_lead_verify_token'),
            'meta_default_project_id' => (int)($data['meta_default_project_id'] ?? 0),
            'meta_default_assigned_to' => (int)($data['meta_default_assigned_to'] ?? 0),
        ];

        foreach ($fields as $key => $val) {
            $stmt = $this->db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, (string)$val, (string)$val]);
        }
        $this->loadSettings();
    }

    public function isConfigured(): bool {
        return !empty($this->accessToken) && !empty($this->pageId);
    }

    public function getAccessToken(): string { return $this->accessToken; }
    public function getPageId(): string { return $this->pageId; }
    public function getVerifyToken(): string { return $this->verifyToken; }
    public function getDefaultProjectId(): int { return $this->defaultProjectId; }
    public function getDefaultAssignedTo(): int { return $this->defaultAssignedTo; }

    /**
     * Handle Meta Graph API errors cleanly
     */
    private function parseGraphError(array $error, int $httpCode): Exception {
        $msg     = $error['message'] ?? 'Unknown Meta API error.';
        $code    = $error['code'] ?? 0;
        $subcode = $error['error_subcode'] ?? 0;
        $lowerMsg = strtolower($msg);

        if ($code == 190 || $subcode == 463 || $subcode == 467 || str_contains($lowerMsg, 'expired') || str_contains($lowerMsg, 'session has expired') || str_contains($lowerMsg, 'invalid access token')) {
            return new Exception("Meta Page Access Token Expired: The configured Page Access Token has expired or is invalid. Please generate a new System User Token or Long-Lived Token and update your Settings.");
        }

        if ($code == 200 || str_contains($lowerMsg, 'permission') || str_contains($lowerMsg, 'leads_retrieval')) {
            return new Exception("Meta Permission Error: Your access token lacks required permissions (leads_retrieval, pages_show_list, pages_read_engagement, ads_management). Error: {$msg}");
        }

        if ($code == 100 || str_contains($lowerMsg, 'does not exist') || str_contains($lowerMsg, 'unsupported get request')) {
            return new Exception("Meta Page / Object Error: Facebook Page ID or Form ID not found or not accessible with this token. Error: {$msg}");
        }

        return new Exception("Meta API Error (HTTP {$httpCode} / Code {$code}): {$msg}");
    }

    /**
     * Perform HTTP GET to Facebook Graph API
     */
    private function graphGet(string $endpoint, array $params = []): array {
        if (!isset($params['access_token'])) {
            $params['access_token'] = $this->accessToken;
        }

        $url = "https://graph.facebook.com/" . $this->graphApiVersion . "/" . ltrim($endpoint, '/') . '?' . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception("Meta API Request Failed (cURL Error): " . $curlErr);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new Exception("Invalid response from Meta API: " . $response);
        }

        if (isset($decoded['error'])) {
            throw $this->parseGraphError($decoded['error'], $httpCode);
        }

        return $decoded;
    }

    /**
     * Perform HTTP POST to Facebook Graph API
     */
    private function graphPost(string $endpoint, array $params = []): array {
        if (!isset($params['access_token'])) {
            $params['access_token'] = $this->accessToken;
        }
        $url = "https://graph.facebook.com/" . $this->graphApiVersion . "/" . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new Exception("Meta API POST Failed (cURL Error): " . $curlErr);
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new Exception("Invalid POST response from Meta API: " . $response);
        }

        if (isset($decoded['error'])) {
            throw $this->parseGraphError($decoded['error'], $httpCode);
        }

        return $decoded;
    }

    /**
     * Test token & fetch Page Details (Step 1)
     */
    public function fetchPageInfo(): array {
        if (empty($this->accessToken) || empty($this->pageId)) {
            throw new Exception("Meta Page ID and Access Token are required.");
        }
        return $this->graphGet($this->pageId, [
            'fields' => 'id,name,category,link,tasks'
        ]);
    }

    /**
     * Get pages accessible with a User Access Token (Step 1 option)
     * GET https://graph.facebook.com/v21.0/me/accounts?access_token=USER_ACCESS_TOKEN
     */
    public function getPagesFromUserToken(string $userAccessToken): array {
        $res = $this->graphGet('me/accounts', [
            'access_token' => $userAccessToken,
            'fields' => 'id,name,access_token,category'
        ]);
        return $res['data'] ?? [];
    }

    /**
     * List lead forms on the page (Step 2)
     * GET https://graph.facebook.com/v21.0/PAGE_ID/leadgen_forms?access_token=PAGE_ACCESS_TOKEN
     */
    public function fetchLeadForms(): array {
        if (empty($this->accessToken) || empty($this->pageId)) {
            throw new Exception("Meta Page ID and Access Token are required.");
        }
        $res = $this->graphGet($this->pageId . '/leadgen_forms', [
            'fields' => 'id,name,status,created_time,leads_count',
            'limit'  => 100
        ]);
        return $res['data'] ?? [];
    }

    /**
     * Fetch leads from a form with pagination support (Step 3 & 4)
     * GET https://graph.facebook.com/v21.0/FORM_ID/leads?access_token=PAGE_ACCESS_TOKEN&limit=100
     */
    public function fetchLeadsFromForm(string $formId, int $limit = 100): array {
        $leads = [];
        $endpoint = $formId . '/leads';
        $params = [
            'fields' => 'created_time,id,field_data,form_id,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name',
            'limit'  => $limit
        ];

        while ($endpoint) {
            $res = $this->graphGet($endpoint, $params);
            if (!empty($res['data'])) {
                foreach ($res['data'] as $item) {
                    $leads[] = $item;
                }
            }

            // Check pagination next
            if (isset($res['paging']['next']) && !empty($res['paging']['next'])) {
                $nextUrl = $res['paging']['next'];
                $parsed = parse_url($nextUrl);
                if (isset($parsed['path'])) {
                    $pathParts = explode('/' . $this->graphApiVersion . '/', $parsed['path']);
                    $endpoint = end($pathParts);
                    parse_str($parsed['query'] ?? '', $params);
                } else {
                    $endpoint = null;
                }
            } else {
                $endpoint = null;
            }
        }

        return $leads;
    }

    /**
     * Fetch a single lead by Leadgen ID (Step 5 - Real-time webhook flow)
     * GET https://graph.facebook.com/v21.0/{leadgen_id}?access_token=PAGE_ACCESS_TOKEN
     */
    public function fetchSingleLead(string $leadgenId): array {
        return $this->graphGet($leadgenId, [
            'fields' => 'created_time,id,field_data,form_id,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name'
        ]);
    }

    /**
     * Subscribe Page to Webhook Subscribed Apps (Step 5.2)
     * POST https://graph.facebook.com/v21.0/PAGE_ID/subscribed_apps?subscribed_fields=leadgen&access_token=PAGE_ACCESS_TOKEN
     */
    public function subscribeAppToPage(): array {
        if (empty($this->accessToken) || empty($this->pageId)) {
            throw new Exception("Meta Page ID and Access Token are required.");
        }
        return $this->graphPost($this->pageId . '/subscribed_apps', [
            'subscribed_fields' => 'leadgen'
        ]);
    }

    /**
     * Parse Meta field_data array into standard lead array
     */
    public function parseMetaFieldData(array $fieldData): array {
        $extracted = [
            'name' => '',
            'first_name' => '',
            'last_name' => '',
            'phone' => '',
            'email' => '',
            'company' => '',
            'designation' => '',
            'city' => '',
            'address' => '',
            'website' => '',
            'custom_fields' => []
        ];

        foreach ($fieldData as $field) {
            $name = strtolower(trim($field['name'] ?? ''));
            $values = $field['values'] ?? [];
            $val = trim($values[0] ?? '');

            if (empty($val)) continue;

            if (in_array($name, ['full_name', 'full name', 'name', 'your_name'])) {
                $extracted['name'] = $val;
            } elseif (in_array($name, ['first_name', 'first name'])) {
                $extracted['first_name'] = $val;
            } elseif (in_array($name, ['last_name', 'last name'])) {
                $extracted['last_name'] = $val;
            } elseif (in_array($name, ['phone_number', 'phone number', 'phone', 'mobile', 'contact_number', 'contact'])) {
                $extracted['phone'] = $val;
            } elseif (in_array($name, ['email', 'email_address', 'e-mail'])) {
                $extracted['email'] = $val;
            } elseif (in_array($name, ['company_name', 'company', 'business_name', 'organization'])) {
                $extracted['company'] = $val;
            } elseif (in_array($name, ['job_title', 'work_title', 'designation', 'position', 'role'])) {
                $extracted['designation'] = $val;
            } elseif (in_array($name, ['city', 'town'])) {
                $extracted['city'] = $val;
            } elseif (in_array($name, ['street_address', 'address', 'location'])) {
                $extracted['address'] = $val;
            } elseif (in_array($name, ['website', 'company_website'])) {
                $extracted['website'] = $val;
            } else {
                $extracted['custom_fields'][$field['name'] ?? 'Field'] = $val;
            }
        }

        if (empty($extracted['name'])) {
            $extracted['name'] = trim($extracted['first_name'] . ' ' . $extracted['last_name']);
        }

        if (empty($extracted['address']) && !empty($extracted['city'])) {
            $extracted['address'] = $extracted['city'];
        }

        return $extracted;
    }

    /**
     * Save a Meta lead into the database.
     * Prevents duplicate leads by checking meta_lead_id or phone number.
     */
    public function saveLead(array $metaLeadRaw, ?string $formName = null): array {
        $metaLeadId = (string)($metaLeadRaw['id'] ?? '');
        if (empty($metaLeadId)) {
            throw new Exception("Invalid lead structure: Missing Meta lead ID.");
        }

        // Check duplicate by meta_lead_id
        $dupStmt = $this->db->prepare("SELECT id, lead_code FROM leads WHERE meta_lead_id = ?");
        $dupStmt->execute([$metaLeadId]);
        $existing = $dupStmt->fetch();
        if ($existing) {
            return [
                'success' => true,
                'status' => 'duplicate',
                'lead_id' => (int)$existing['id'],
                'lead_code' => $existing['lead_code'],
                'message' => 'Lead already exists in system.'
            ];
        }

        // Parse fields
        $fieldData = $metaLeadRaw['field_data'] ?? [];
        $parsed = $this->parseMetaFieldData($fieldData);

        $name        = !empty($parsed['name']) ? $parsed['name'] : ('Meta Lead #' . substr($metaLeadId, -6));
        $phone       = !empty($parsed['phone']) ? $parsed['phone'] : 'N/A';
        $email       = $parsed['email'];
        $company     = $parsed['company'];
        $designation = $parsed['designation'];
        $website     = $parsed['website'];
        $address     = $parsed['address'];

        // Format notes
        $formTitle = $formName ?? $metaLeadRaw['ad_name'] ?? $metaLeadRaw['campaign_name'] ?? 'Meta Lead Ad';
        $notesParts = [];
        $notesParts[] = "[Meta Lead Form: " . $formTitle . "]";
        if (!empty($metaLeadRaw['campaign_name'])) {
            $notesParts[] = "Campaign: " . $metaLeadRaw['campaign_name'];
        }
        if (!empty($metaLeadRaw['ad_name'])) {
            $notesParts[] = "Ad: " . $metaLeadRaw['ad_name'];
        }
        if (!empty($parsed['custom_fields'])) {
            foreach ($parsed['custom_fields'] as $k => $v) {
                $notesParts[] = "$k: $v";
            }
        }
        $notes = implode(" | ", $notesParts);

        $projectId = $this->defaultProjectId;
        if ($projectId <= 0) {
            $projectId = (int)$this->db->query("SELECT id FROM projects WHERE status='active' ORDER BY id ASC LIMIT 1")->fetchColumn();
        }

        if ($projectId <= 0) {
            throw new Exception("No active project found in database to assign lead.");
        }

        // Duplicate check by phone in same project
        if ($phone !== 'N/A') {
            $pDup = $this->db->prepare("SELECT id, lead_code FROM leads WHERE phone = ? AND project_id = ? LIMIT 1");
            $pDup->execute([$phone, $projectId]);
            $pExist = $pDup->fetch();
            if ($pExist) {
                // Link meta_lead_id
                $this->db->prepare("UPDATE leads SET meta_lead_id = ? WHERE id = ?")->execute([$metaLeadId, $pExist['id']]);
                return [
                    'success' => true,
                    'status' => 'linked_duplicate',
                    'lead_id' => (int)$pExist['id'],
                    'lead_code' => $pExist['lead_code'],
                    'message' => 'Lead matched existing phone number.'
                ];
            }
        }

        // Generate Lead Code
        $leadCode = generateCode('LD', 'leads', 'lead_code');
        $assignedTo = $this->defaultAssignedTo > 0 ? $this->defaultAssignedTo : null;

        $query = "INSERT INTO leads (
                    lead_code, meta_lead_id, project_id, name, email,
                    phone, company, designation, website, address,
                    source, status, assigned_to, notes, created_at
                  ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'social_media', 'new', ?, ?, NOW())";

        $stmt = $this->db->prepare($query);
        $stmt->execute([
            $leadCode,
            $metaLeadId,
            $projectId,
            $name,
            $email,
            $phone,
            $company,
            $designation,
            $website,
            $address,
            $assignedTo,
            $notes
        ]);

        $leadId = (int)$this->db->lastInsertId();

        return [
            'success' => true,
            'status' => 'imported',
            'lead_id' => $leadId,
            'lead_code' => $leadCode,
            'name' => $name,
            'phone' => $phone,
            'message' => 'Lead saved successfully.'
        ];
    }

    /**
     * Run full sync of all forms and leads
     */
    public function syncAllLeads(): array {
        if (!$this->isConfigured()) {
            throw new Exception("Meta Lead Integration is not configured yet. Please provide Page Access Token and Page ID in Settings.");
        }

        $forms = $this->fetchLeadForms();
        $summary = [
            'forms_scanned' => count($forms),
            'total_leads_found' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'details' => []
        ];

        foreach ($forms as $form) {
            $formId = $form['id'];
            $formName = $form['name'] ?? ('Form #' . $formId);
            try {
                $rawLeads = $this->fetchLeadsFromForm($formId);
                $summary['total_leads_found'] += count($rawLeads);

                foreach ($rawLeads as $rawLead) {
                    try {
                        $res = $this->saveLead($rawLead, $formName);
                        if ($res['status'] === 'imported') {
                            $summary['imported']++;
                        } else {
                            $summary['duplicates']++;
                        }
                    } catch (Exception $e) {
                        $summary['errors']++;
                        $summary['details'][] = "Form {$formName} Lead ID {$rawLead['id']}: " . $e->getMessage();
                    }
                }
            } catch (Exception $e) {
                $summary['errors']++;
                $summary['details'][] = "Error fetching leads for form '{$formName}': " . $e->getMessage();
            }
        }

        $now = date('Y-m-d H:i:s');
        $this->db->prepare("INSERT INTO app_settings (setting_key, setting_value) VALUES ('meta_last_sync_time', ?) ON DUPLICATE KEY UPDATE setting_value = ?")->execute([$now, $now]);

        return $summary;
    }
}
