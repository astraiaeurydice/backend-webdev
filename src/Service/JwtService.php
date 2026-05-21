<?php

namespace App\Service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private string $jwtSecret;

    public function __construct(string $jwtSecret)
    {
        $this->jwtSecret = $jwtSecret;
    }

    public function generateToken(array $payload, int $expirySeconds = 3600): string
    {
        $issuedAt = time();
        $expire = $issuedAt + $expirySeconds;

        $token = [
            "iat" => $issuedAt,
            "exp" => $expire,
            "data" => $payload
        ];

        return JWT::encode($token, $this->jwtSecret, 'HS256');
    }

    private function sanitizeToken(string $token): string
    {
        $t = trim($token);
        // Allow callers to pass "Bearer <token>"
        if (str_starts_with(strtolower($t), 'bearer ')) {
            $t = trim(substr($t, 7));
        }
        // Remove accidental quotes
        $t = trim($t, "\"' \t\n\r\0\x0B");
        return $t;
    }

    /**
     * Decode the full token payload (including iat, exp, data)
     */
    public function decodeToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($this->sanitizeToken($token), new Key($this->jwtSecret, 'HS256'));
            return (array)$decoded;
        } catch (\Exception $e) {
            error_log('JWT decode error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate token and return only the data payload
     */
    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($this->sanitizeToken($token), new Key($this->jwtSecret, 'HS256'));
            // $decoded is a stdClass object with properties: iat, exp, data
            // $decoded->data is also a stdClass with: id, email, roles
            // Convert to array using json_decode/json_encode to properly handle nested objects
            return json_decode(json_encode($decoded->data), true);
        } catch (\Exception $e) {
            error_log('JWT validation error: ' . $e->getMessage());
            return null;
        }
    }
}