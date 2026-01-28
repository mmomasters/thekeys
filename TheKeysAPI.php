<?php
/**
 * The Keys Cloud API Client
 * Complete CRUD operations for keypad codes
 */

class TheKeysAPI {
    private $username;
    private $password;
    private $base_url;
    private $token;
    private $headers = [];
    
    public function __construct($username, $password, $base_url = "https://api.the-keys.fr") {
        $this->username = $username;
        $this->password = $password;
        $this->base_url = $base_url;
    }
    
    /**
     * Authenticate and get JWT token
     */
    public function login() {
        $url = $this->base_url . '/api/login_check';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            '_username' => $this->username,
            '_password' => $this->password
        ]));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (isset($data['token'])) {
                $this->token = $data['token'];
                $this->headers = [
                    'Authorization: Bearer ' . $this->token
                ];
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * List all keypad codes for a lock
     */
    public function listCodes($lock_id) {
        $url = $this->base_url . "/fr/api/v2/partage/all/serrure/{$lock_id}?_format=json";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            return $data['data']['partages_accessoire'] ?? [];
        }
        
        return [];
    }
    
    /**
     * Create a new keypad code
     */
    public function createCode($lock_id, $id_accessoire, $name, $code, $date_start, $date_end,
                               $time_start_hour = "15", $time_start_min = "0",
                               $time_end_hour = "12", $time_end_min = "0", $description = "") {
        
        $url = $this->base_url . "/fr/api/v2/partage/create/{$lock_id}/accessoire/{$id_accessoire}";
        
        $data = [
            "partage_accessoire[nom]" => $name,
            "partage_accessoire[actif]" => "1",
            "partage_accessoire[date_debut]" => $date_start,
            "partage_accessoire[date_fin]" => $date_end,
            "partage_accessoire[heure_debut][hour]" => $time_start_hour,
            "partage_accessoire[heure_debut][minute]" => $time_start_min,
            "partage_accessoire[heure_fin][hour]" => $time_end_hour,
            "partage_accessoire[heure_fin][minute]" => $time_end_min,
            // DON'T send notification_enabled - omitting it disables notifications!
            "partage_accessoire[code]" => $code,
            "partage_accessoire[description]" => $description
        ];
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            if (isset($result['status']) && $result['status'] === 200) {
                return $result['data'] ?? null;
            }
        }
        
        return null;
    }
    
    /**
     * Update an existing keypad code
     */
    public function updateCode($code_id, $name = null, $code = null, $date_start = null, $date_end = null,
                               $time_start_hour = null, $time_start_min = null,
                               $time_end_hour = null, $time_end_min = null,
                               $active = true, $description = null) {
        
        $url = $this->base_url . "/fr/api/v2/partage/accessoire/update/{$code_id}";
        
        $data = [
            "partage_accessoire[actif]" => $active ? "1" : "0"
            // DON'T send notification_enabled - omitting it keeps notifications disabled!
        ];
        
        if ($name !== null) $data["partage_accessoire[nom]"] = $name;
        if ($code !== null) $data["partage_accessoire[code]"] = $code;
        if ($date_start !== null) $data["partage_accessoire[date_debut]"] = $date_start;
        if ($date_end !== null) $data["partage_accessoire[date_fin]"] = $date_end;
        if ($time_start_hour !== null) {
            $data["partage_accessoire[heure_debut][hour]"] = $time_start_hour;
            $data["partage_accessoire[heure_debut][minute]"] = $time_start_min ?? "0";
        }
        if ($time_end_hour !== null) {
            $data["partage_accessoire[heure_fin][hour]"] = $time_end_hour;
            $data["partage_accessoire[heure_fin][minute]"] = $time_end_min ?? "0";
        }
        if ($description !== null) $data["partage_accessoire[description]"] = $description;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return isset($result['status']) && $result['status'] === 200;
        }
        
        return false;
    }
    
    /**
     * Delete a keypad code
     */
    public function deleteCode($code_id) {
        $url = $this->base_url . "/fr/api/v2/partage/accessoire/delete/{$code_id}";
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $this->headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code === 200) {
            $result = json_decode($response, true);
            return isset($result['status']) && $result['status'] === 200;
        }
        
        return false;
    }
}
