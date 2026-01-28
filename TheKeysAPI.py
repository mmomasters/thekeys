#!/usr/bin/env python3
"""
The Keys Cloud API Client
Complete CRUD operations for keypad codes
"""
import requests
import urllib3
from typing import Dict, List, Optional
from datetime import datetime

urllib3.disable_warnings()


class TheKeysAPI:
    """Clean Python client for The Keys Cloud REST API"""
    
    def __init__(self, username: str, password: str, base_url: str = "https://api.the-keys.fr"):
        self.username = username
        self.password = password
        self.base_url = base_url
        self.token = None
        self.headers = {}
        
    def login(self) -> bool:
        """Authenticate and get JWT token"""
        try:
            r = requests.post(
                f'{self.base_url}/api/login_check',
                data={'_username': self.username, '_password': self.password},
                verify=False
            )
            if r.status_code == 200:
                self.token = r.json()['token']
                self.headers = {"Authorization": f"Bearer {self.token}"}
                return True
        except Exception as e:
            print(f"Login error: {e}")
        return False
    
    def list_codes(self, lock_id: int) -> List[Dict]:
        """List all keypad codes for a lock"""
        url = f'{self.base_url}/fr/api/v2/partage/all/serrure/{lock_id}?_format=json'
        r = requests.get(url, headers=self.headers, verify=False)
        
        if r.status_code == 200:
            data = r.json()
            return data.get('data', {}).get('partages_accessoire', [])
        return []
    
    def create_code(self, lock_id: int, id_accessoire: str, name: str, code: str,
                   date_start: str, date_end: str, 
                   time_start_hour: str = "15", time_start_min: str = "0",
                   time_end_hour: str = "12", time_end_min: str = "0",
                   description: str = "") -> Optional[Dict]:
        """
        Create a new keypad code
        
        Args:
            lock_id: Lock ID (e.g., 3723)
            id_accessoire: STRING accessoire ID (e.g., "OXe37UIa")
            name: Guest name
            code: PIN code (4 digits)
            date_start: Start date (YYYY-MM-DD)
            date_end: End date (YYYY-MM-DD)
            time_start_hour: Check-in hour (default 15)
            time_start_min: Check-in minute (default 0)
            time_end_hour: Check-out hour (default 12)
            time_end_min: Check-out minute (default 0)
            description: Optional description
        
        Returns:
            Dict with 'id' and 'code' if successful
        """
        url = f'{self.base_url}/fr/api/v2/partage/create/{lock_id}/accessoire/{id_accessoire}'
        
        data = {
            "partage_accessoire[nom]": name,
            "partage_accessoire[actif]": "1",
            "partage_accessoire[date_debut]": date_start,
            "partage_accessoire[date_fin]": date_end,
            "partage_accessoire[heure_debut][hour]": time_start_hour,
            "partage_accessoire[heure_debut][minute]": time_start_min,
            "partage_accessoire[heure_fin][hour]": time_end_hour,
            "partage_accessoire[heure_fin][minute]": time_end_min,
            # DON'T send notification_enabled - omitting it disables notifications!
            "partage_accessoire[code]": code,
            "partage_accessoire[description]": description
        }
        
        r = requests.post(url, headers=self.headers, data=data, verify=False)
        
        if r.status_code == 200:
            result = r.json()
            if result.get('status') == 200:
                return result.get('data')
        return None
    
    def update_code(self, code_id: int, name: str = None, code: str = None,
                   date_start: str = None, date_end: str = None,
                   time_start_hour: str = None, time_start_min: str = None,
                   time_end_hour: str = None, time_end_min: str = None,
                   active: bool = True, description: str = None) -> bool:
        """
        Update an existing keypad code
        
        Args:
            code_id: Code ID to update
            Other args: Same as create_code (optional - only updates provided fields)
        
        Returns:
            True if successful
        """
        url = f'{self.base_url}/fr/api/v2/partage/accessoire/update/{code_id}'
        
        data = {
            "partage_accessoire[actif]": "1" if active else "0"
            # DON'T send notification_enabled - omitting it keeps notifications disabled!
        }
        
        if name:
            data["partage_accessoire[nom]"] = name
        if code:
            data["partage_accessoire[code]"] = code
        if date_start:
            data["partage_accessoire[date_debut]"] = date_start
        if date_end:
            data["partage_accessoire[date_fin]"] = date_end
        if time_start_hour:
            data["partage_accessoire[heure_debut][hour]"] = time_start_hour
            data["partage_accessoire[heure_debut][minute]"] = time_start_min or "0"
        if time_end_hour:
            data["partage_accessoire[heure_fin][hour]"] = time_end_hour
            data["partage_accessoire[heure_fin][minute]"] = time_end_min or "0"
        if description is not None:
            data["partage_accessoire[description]"] = description
        
        r = requests.post(url, headers=self.headers, data=data, verify=False)
        
        if r.status_code == 200:
            result = r.json()
            return result.get('status') == 200
        return False
    
    def delete_code(self, code_id: int) -> bool:
        """
        Delete a keypad code
        
        Args:
            code_id: Code ID to delete
        
        Returns:
            True if successful
        """
        url = f'{self.base_url}/fr/api/v2/partage/accessoire/delete/{code_id}'
        r = requests.post(url, headers=self.headers, verify=False)
        
        if r.status_code == 200:
            result = r.json()
            return result.get('status') == 200
        return False
