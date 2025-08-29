<?php
class RiskCalculatorClient {
    private $base_url;
    private $timeout;
    
    public function __construct($base_url = 'http://localhost:5000', $timeout = 30) {
        $this->base_url = rtrim($base_url, '/');
        $this->timeout = $timeout;
    }
    
    public function calculateRiskRating($likelihood, $consequence) {
        $data = [
            'likelihood' => (int)$likelihood,
            'consequence' => (int)$consequence
        ];
        
        $response = $this->makeRequest('/api/calculate-risk', $data);
        
        if ($response && isset($response['rating'])) {
            return $response;
        }
        
        // Fallback to PHP calculation if service unavailable
        return $this->fallbackCalculateRisk($likelihood, $consequence);
    }
    
    private function makeRequest($endpoint, $data = null, $method = 'POST') {
        $url = $this->base_url . $endpoint;
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);
        
        if ($data && $method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $http_code !== 200) {
            return null;
        }
        
        return json_decode($response, true);
    }
    
    private function fallbackCalculateRisk($likelihood, $consequence) {
        $rating = (int)$likelihood * (int)$consequence;
        
        $level = 'Low';
        if ($rating >= 12) $level = 'Critical';      // 4x3 or 3x4 combinations
        elseif ($rating >= 8) $level = 'High';       // 4x2, 2x4 combinations  
        elseif ($rating >= 4) $level = 'Medium';     // 4x1, 2x2, 1x4 combinations
        
        return [
            'rating' => $rating,
            'level' => $level,
            'likelihood' => (int)$likelihood,
            'consequence' => (int)$consequence,
            'fallback' => true
        ];
    }
}
?>
