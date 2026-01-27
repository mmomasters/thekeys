<?php
/**
 * The Keys API - Complete Working Version
 * Automatically manages keypad codes for The Keys smart locks
 * 
 * @version 2.0
 */

class TheKeysAPI {
    
    private $username;
    private $password;
    private $baseUrl = 'https://api.the-keys.fr';
    private $cookieFile;
    private $isLoggedIn = false;
    
    /**
     * Lock ID to Accessoire (Keypad) ID mapping
     */
    private $lockAccessoireMap = [];
    
    public function __construct($username, $password) {
        $this->username = $username;
        $this->password = $password;
        $this->cookieFile = sys_get_temp_dir() . '/thekeys_session_' . md5($username) . '.txt';
    }
    
    /**
     * Make HTTP request
     */
    private function request($url, $post = null, $referer = null) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $this->cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $this->cookieFile);
        curl_setopt($ch, CURLOPT_HEADER, true);
        
        $headers = [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ];
        
        if ($referer) {
            $headers[] = 'Referer: ' . $referer;
        }
        
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        
        $headers = substr($response, 0, $headerSize);
        $html = substr($response, $headerSize);
        
        return [
            'html' => $html,
            'headers' => $headers,
            'code' => $code,
            'url' => $url
        ];
    }
    
    /**
     * Check if logged in
     */
    private function checkAuth() {
        $result = $this->request($this->baseUrl . '/en/compte/serrure');
        return strpos($result['url'], '/login') === false && $result['code'] == 200;
    }
    
    /**
     * Login to The Keys
     */
    public function login() {
        // Check if already logged in
        if ($this->checkAuth()) {
            $this->isLoggedIn = true;
            return true;
        }
        
        // Get login page
        $result = $this->request($this->baseUrl . '/auth/en/login');
        
        if ($result['code'] != 200) {
            throw new Exception("Cannot access login page: HTTP {$result['code']}");
        }
        
        // Submit login
        $postData = [
            '_username' => $this->username,
            '_password' => $this->password,
        ];
        
        $result = $this->request(
            $this->baseUrl . '/auth/en/login_check',
            $postData,
            $this->baseUrl . '/auth/en/login'
        );
        
        // Verify by checking if we can access a protected page
        if (!$this->checkAuth()) {
            $error = 'Authentication verification failed';
            if (preg_match('/<div[^>]*class=["\'][^"\']*alert[^"\']*["\'][^>]*>(.*?)<\/div>/is', $result['html'], $m)) {
                $error = strip_tags($m[1]);
            }
            throw new Exception("Login failed: $error");
        }
        
        $this->isLoggedIn = true;
        return true;
    }
    
    /**
     * Ensure logged in before making requests
     */
    private function ensureLoggedIn() {
        if (!$this->isLoggedIn) {
            $this->login();
        }
    }
    
    /**
     * Get the accessoire (keypad) ID for a lock
     */
    private function getAccessoireId($lockId) {
        if (isset($this->lockAccessoireMap[$lockId])) {
            return $this->lockAccessoireMap[$lockId];
        }
        
        throw new Exception("No accessoire mapping found for lock $lockId. Please add it to lockAccessoireMap.");
    }
    
    /**
     * Get the create form for a lock
     */
    private function getCreateForm($lockId) {
        $url = $this->baseUrl . "/en/compte/partage/accessoire/create/$lockId?type=digicode";
        $result = $this->request($url);
        
        if ($result['code'] != 200) {
            throw new Exception("Cannot access create form: HTTP {$result['code']}");
        }
        
        // Check if redirected to login
        if (strpos($result['url'], '/login') !== false) {
            throw new Exception("Not authenticated - session expired");
        }
        
        // Extract form
        if (!preg_match('/<form[^>]*>(.*?)<\/form>/is', $result['html'], $formMatch)) {
            throw new Exception("No form found on create page");
        }
        
        $formHtml = $formMatch[0];
        
        // Extract _token (REQUIRED)
        $token = null;
        if (preg_match('/partage_accessoire\[_token\]["\'][^>]*value=["\']([^"\']+)["\']/', $formHtml, $m)) {
            $token = $m[1];
        }
        
        if (!$token) {
            throw new Exception("Cannot find form token");
        }
        
        // Extract action URL
        $action = "/en/compte/partage/accessoire/create/$lockId";
        if (preg_match('/action=["\']([^"\']+)["\']/', $formHtml, $m)) {
            $action = $m[1];
        }
        
        return [
            'action' => $action,
            'token' => $token,
        ];
    }
    
    /**
     * Create keypad access code
     * 
     * @param int $lockId Lock ID (3723, 3733, 3735, etc.)
     * @param array $data:
     *   - guestName: string (required)
     *   - startDate: string YYYY-MM-DD (required)
     *   - endDate: string YYYY-MM-DD (required)
     *   - startTime: string HH:MM (optional, default 15:00 check-in)
     *   - endTime: string HH:MM (optional, default 12:00 check-out)
     *   - code: string (optional, leave empty for auto-generation)
     *   - description: string (optional)
     * @return array Success details
     */
    public function createCode($lockId, $data) {
        $this->ensureLoggedIn();
        
        // Validate required fields
        if (empty($data['guestName'])) {
            throw new Exception("guestName is required");
        }
        if (empty($data['startDate'])) {
            throw new Exception("startDate is required");
        }
        if (empty($data['endDate'])) {
            throw new Exception("endDate is required");
        }
        
        // Get accessoire ID
        $accessoireId = $this->getAccessoireId($lockId);
        
        // Get form (includes CSRF token)
        $form = $this->getCreateForm($lockId);
        
        // Parse times
        $startTime = $data['startTime'] ?? '15:00';
        $endTime = $data['endTime'] ?? '12:00';
        
        list($startHour, $startMinute) = explode(':', $startTime);
        list($endHour, $endMinute) = explode(':', $endTime);
        
        // Build POST data
        $postData = [
            'partage_accessoire[actif]' => '1',
            'partage_accessoire[notification_enabled]' => '1',
            'partage_accessoire[nom]' => $data['guestName'],
            'partage_accessoire[accessoire]' => $accessoireId,
            'partage_accessoire[code]' => $data['code'] ?? '',
            'partage_accessoire[date_debut]' => $data['startDate'],
            'partage_accessoire[date_fin]' => $data['endDate'],
            'partage_accessoire[description]' => $data['description'] ?? '',
            'partage_accessoire[_token]' => $form['token'],
            'partage_accessoire[heure_debut][hour]' => (int)$startHour,
            'partage_accessoire[heure_debut][minute]' => (int)$startMinute,
            'partage_accessoire[heure_fin][hour]' => (int)$endHour,
            'partage_accessoire[heure_fin][minute]' => (int)$endMinute,
        ];
        
        // Submit form
        $actionUrl = $this->baseUrl . $form['action'];
        $result = $this->request($actionUrl, $postData, $actionUrl);
        
        // Success check: should redirect away from /create
        if (strpos($result['url'], '/create') !== false) {
            $error = 'Unknown error';
            if (preg_match('/<span[^>]*class=["\'][^"\']*(?:help-block|error|invalid-feedback)[^"\']*["\'][^>]*>(.*?)<\/span>/is', $result['html'], $m)) {
                $error = strip_tags($m[1]);
            } elseif (preg_match('/<div[^>]*class=["\'][^"\']*alert[^"\']*["\'][^>]*>(.*?)<\/div>/is', $result['html'], $m)) {
                $text = strip_tags($m[1]);
                if (strlen($text) < 200) {
                    $error = $text;
                }
            }
            throw new Exception("Failed to create code: $error");
        }
        
        return [
            'success' => true,
            'lockId' => $lockId,
            'accessoireId' => $accessoireId,
            'guestName' => $data['guestName'],
            'startDate' => $data['startDate'],
            'endDate' => $data['endDate'],
            'code' => $data['code'] ?? 'auto-generated',
            'url' => $result['url']
        ];
    }
    
    /**
     * Delete access code
     * 
     * @param int $codeId The code ID to delete
     * @return array Success details
     */
    public function deleteCode($codeId) {
        $this->ensureLoggedIn();
        
        $url = $this->baseUrl . "/en/compte/partage/accessoire/$codeId/delete";
        $result = $this->request($url);
        
        return [
            'success' => strpos($result['url'], '/delete') === false,
            'codeId' => $codeId
        ];
    }
    
/**
 * Get all codes for a lock with details
 * 
 * @param int $lockId Lock ID
 * @param bool $debug If true, returns debug information
 * @return array List of codes with IDs, names, and dates
 */
public function getAllCodes($lockId, $debug = false) {
    $this->ensureLoggedIn();
    
    // Use the lock ID to view shared codes for that specific lock
    $url = $this->baseUrl . "/en/compte/serrure/$lockId/view_partage";
    $result = $this->request($url);
    
    // Debug mode: return raw HTML even if error
    if ($debug) {
        return [
            'debug' => true,
            'lock_id' => $lockId,
            'url' => $url,
            'http_code' => $result['code'],
            'effective_url' => $result['url'],
            'html_snippet' => substr($result['html'], 0, 5000),
            'html_length' => strlen($result['html']),
            'is_200' => $result['code'] == 200,
            'redirected_to_login' => strpos($result['url'], '/login') !== false
        ];
    }
    
    if ($result['code'] != 200) {
        throw new Exception("Cannot access codes page (HTTP {$result['code']})");
    }
    
    $codes = [];
    
    // Parse the HTML table more carefully
    // Look for table rows with code information
    if (preg_match_all('/<tr[^>]*>(.*?)<\/tr>/is', $result['html'], $rows)) {
        foreach ($rows[1] as $rowHtml) {
            // Skip header rows
            if (stripos($rowHtml, '<th') !== false) {
                continue;
            }
            
            // Look for the accessoire ID in delete/edit links
            if (preg_match('/\/partage\/accessoire\/(\d+)\/(delete|edit|get)/', $rowHtml, $idMatch)) {
                $codeId = $idMatch[1];
                
                // Extract guest name - try multiple patterns
                $name = 'Unknown';
                
                // Pattern 1: Link text (most common)
                if (preg_match('/<a[^>]*href=["\'][^"\']*\/partage\/accessoire\/\d+\/[^"\']*["\'][^>]*>([^<]+)<\/a>/i', $rowHtml, $nameMatch)) {
                    $name = trim(strip_tags($nameMatch[1]));
                }
                // Pattern 2: First td cell content
                elseif (preg_match('/<td[^>]*>([^<]+)<\/td>/i', $rowHtml, $tdMatch)) {
                    $name = trim(strip_tags($tdMatch[1]));
                }
                
                // Extract dates if available
                $startDate = null;
                $endDate = null;
                
                // Look for date patterns (YYYY-MM-DD or DD/MM/YYYY)
                if (preg_match_all('/(\d{4}-\d{2}-\d{2}|\d{2}\/\d{2}\/\d{4})/', $rowHtml, $dateMatches)) {
                    if (isset($dateMatches[1][0])) {
                        $startDate = $dateMatches[1][0];
                    }
                    if (isset($dateMatches[1][1])) {
                        $endDate = $dateMatches[1][1];
                    }
                }
                
                // Only add if we got a valid name (not just numbers)
                if (!empty($name) && !is_numeric($name)) {
                    $codes[] = [
                        'id' => $codeId,
                        'name' => $name,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'raw_html' => $rowHtml
                    ];
                }
            }
        }
    }
    
    return $codes;
}

/**
 * Get detailed information about a specific code
 * 
 * @param int $codeId Code ID
 * @return array Code details including PIN, dates, etc.
 */
public function getCodeDetails($codeId) {
    $this->ensureLoggedIn();
    
    $url = $this->baseUrl . "/en/compte/partage/accessoire/$codeId/get";
    $result = $this->request($url);
    
    if ($result['code'] != 200) {
        throw new Exception("Cannot access code details");
    }
    
    $details = [
        'id' => $codeId,
        'name' => null,
        'code' => null,
        'start_date' => null,
        'end_date' => null,
        'start_time' => null,
        'end_time' => null,
    ];
    
    // Extract name
    if (preg_match('/partage_accessoire\[nom\]["\'][^>]*value=["\']([^"\']+)["\']/', $result['html'], $m)) {
        $details['name'] = $m[1];
    }
    
    // Extract PIN code
    if (preg_match('/partage_accessoire\[code\]["\'][^>]*value=["\']([^"\']+)["\']/', $result['html'], $m)) {
        $details['code'] = $m[1];
    }
    
    // Extract dates
    if (preg_match('/partage_accessoire\[date_debut\]["\'][^>]*value=["\']([^"\']+)["\']/', $result['html'], $m)) {
        $details['start_date'] = $m[1];
    }
    if (preg_match('/partage_accessoire\[date_fin\]["\'][^>]*value=["\']([^"\']+)["\']/', $result['html'], $m)) {
        $details['end_date'] = $m[1];
    }
    
    // Extract times
    if (preg_match('/partage_accessoire\[heure_debut\]\[hour\]["\'][^>]*value=["\'](\d+)["\']/', $result['html'], $m)) {
        $hour = $m[1];
        $minute = '00';
        if (preg_match('/partage_accessoire\[heure_debut\]\[minute\]["\'][^>]*value=["\'](\d+)["\']/', $result['html'], $m2)) {
            $minute = $m2[1];
        }
        $details['start_time'] = sprintf('%02d:%02d', $hour, $minute);
    }
    
    if (preg_match('/partage_accessoire\[heure_fin\]\[hour\]["\'][^>]*value=["\'](\d+)["\']/', $result['html'], $m)) {
        $hour = $m[1];
        $minute = '00';
        if (preg_match('/partage_accessoire\[heure_fin\]\[minute\]["\'][^>]*value=["\'](\d+)["\']/', $result['html'], $m2)) {
            $minute = $m2[1];
        }
        $details['end_time'] = sprintf('%02d:%02d', $hour, $minute);
    }
    
    return $details;
}

/**
 * Copy a code from one lock to another
 * 
 * @param int $sourceCodeId Source code ID to copy
 * @param int $targetLockId Target lock ID
 * @return array Result of creation
 */
public function copyCode($sourceCodeId, $targetLockId) {
    // Get details from source code
    $details = $this->getCodeDetails($sourceCodeId);
    
    if (empty($details['name'])) {
        throw new Exception("Cannot get details for code $sourceCodeId");
    }
    
    // Create code on target lock with same details
    return $this->createCode($targetLockId, [
        'guestName' => $details['name'],
        'startDate' => $details['start_date'],
        'endDate' => $details['end_date'],
        'startTime' => $details['start_time'] ?? '15:00',
        'endTime' => $details['end_time'] ?? '12:00',
        'code' => $details['code'] ?? '',  // Will auto-generate if empty
        'description' => 'Copied from lock during emergency swap'
    ]);
}
    
    /**
     * Find code by guest name
     * 
     * @param int $lockId Lock ID
     * @param string $guestName Guest name to search for
     * @return array|null Code details with ID, or null if not found
     */
    public function findCodeByGuestName($lockId, $guestName) {
        $codes = $this->getAllCodes($lockId);
        
        // Normalize search name
        $searchName = strtolower(trim($guestName));
        
        foreach ($codes as $code) {
            $codeName = strtolower(trim($code['name']));
            
            // Check for exact match or if code name contains search name
            if ($codeName === $searchName || strpos($codeName, $searchName) !== false) {
                return $code;
            }
        }
        
        return null;
    }
    
    /**
     * Add a lock to accessoire mapping
     * 
     * @param int $lockId Lock ID
     * @param int $accessoireId Accessoire (keypad) ID
     */
    public function setAccessoireMapping($lockId, $accessoireId) {
        $this->lockAccessoireMap[$lockId] = $accessoireId;
    }
}