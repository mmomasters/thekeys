"""
The Keys API Client
Handles authentication and operations with The Keys smart locks
"""

import requests
import json
from typing import Optional, List, Dict
from datetime import datetime
import urllib3

# Disable SSL warnings for development
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)


class TheKeysAPI:
    """Client for The Keys API with token-based authentication"""
    
    def __init__(self, username: str, password: str):
        self.username = username
        self.password = password
        self.base_url = "https://api.the-keys.fr"
        self.token: Optional[str] = None
        self.lock_accessoires: Dict[int, int] = {}
        
    def login(self) -> str:
        """Authenticate and get JWT token"""
        url = f"{self.base_url}/api/login_check"
        data = {
            "_username": self.username,
            "_password": self.password
        }
        
        response = requests.post(url, data=data, verify=False)
        response.raise_for_status()
        
        result = response.json()
        self.token = result.get("token")
        
        if not self.token:
            raise Exception("Failed to get authentication token")
            
        return self.token
    
    def set_accessoire_mapping(self, lock_id: int, accessoire_id: int):
        """Map a lock ID to its accessoire (keypad) ID"""
        self.lock_accessoires[lock_id] = accessoire_id
    
    def get_accessoire_id(self, lock_id: int) -> int:
        """Get accessoire ID for a lock"""
        accessoire_id = self.lock_accessoires.get(lock_id)
        if not accessoire_id:
            raise Exception(f"No accessoire mapping found for lock {lock_id}")
        return accessoire_id
    
    def _make_request(self, method: str, endpoint: str, data: Optional[Dict] = None) -> requests.Response:
        """Make authenticated API request"""
        if not self.token:
            self.login()
        
        url = f"{self.base_url}{endpoint}"
        headers = {
            "Authorization": f"Bearer {self.token}",
            "Content-Type": "application/json"
        }
        
        if method == "GET":
            response = requests.get(url, headers=headers, verify=False)
        elif method == "POST":
            response = requests.post(url, headers=headers, json=data, verify=False)
        elif method == "DELETE":
            response = requests.delete(url, headers=headers, verify=False)
        else:
            raise ValueError(f"Unsupported method: {method}")
        
        response.raise_for_status()
        return response
    
    def get_locks(self) -> List[Dict]:
        """Get all locks for the user"""
        response = self._make_request("GET", "/api/v2/serrure/list")
        return response.json()
    
    def get_lock_codes(self, lock_id: int) -> List[Dict]:
        """Get all access codes for a specific lock"""
        response = self._make_request("GET", f"/api/v2/serrure/{lock_id}/partages")
        return response.json()
    
    def create_code(self, lock_id: int, code_data: Dict) -> Dict:
        """
        Create a new access code
        
        Args:
            lock_id: The lock ID
            code_data: Dict with keys:
                - guestName: str
                - startDate: str (YYYY-MM-DD)
                - endDate: str (YYYY-MM-DD)
                - startTime: str (HH:MM) optional, default 15:00
                - endTime: str (HH:MM) optional, default 12:00
                - code: str optional (4-digit PIN, auto-generated if not provided)
                - description: str optional
        """
        accessoire_id = self.get_accessoire_id(lock_id)
        
        # Parse times
        start_time = code_data.get('startTime', '15:00')
        end_time = code_data.get('endTime', '12:00')
        start_hour, start_minute = map(int, start_time.split(':'))
        end_hour, end_minute = map(int, end_time.split(':'))
        
        # Build API payload
        payload = {
            "nom": code_data['guestName'],
            "accessoire": accessoire_id,
            "code": code_data.get('code', ''),
            "date_debut": code_data['startDate'],
            "date_fin": code_data['endDate'],
            "heure_debut": {
                "hour": start_hour,
                "minute": start_minute
            },
            "heure_fin": {
                "hour": end_hour,
                "minute": end_minute
            },
            "actif": True,
            "notification_enabled": True,
            "description": code_data.get('description', '')
        }
        
        response = self._make_request("POST", f"/api/v2/partage/accessoire/create/{lock_id}", payload)
        return response.json()
    
    def delete_code(self, code_id: int) -> bool:
        """Delete an access code"""
        try:
            self._make_request("DELETE", f"/api/v2/partage/accessoire/{code_id}/delete")
            return True
        except:
            return False
    
    def find_code_by_name(self, lock_id: int, guest_name: str) -> Optional[Dict]:
        """Find a code by guest name"""
        codes = self.get_lock_codes(lock_id)
        
        search_name = guest_name.lower().strip()
        
        for code in codes:
            code_name = code.get('nom', '').lower().strip()
            if code_name == search_name or search_name in code_name:
                return code
        
        return None
