# Laravel Authentication Infrastructure for CTMS Backend

This document outlines the complete authentication and authorization infrastructure for the CTMS (Campus Transportation Management System) backend.

## Table of Contents

1. [Overview](#overview)
2. [Components](#components)
3. [API Endpoints](#api-endpoints)
4. [Authentication Flow](#authentication-flow)
5. [Error Handling](#error-handling)
6. [Usage Examples](#usage-examples)

## Overview

The authentication system is built using:
- **JWT (JSON Web Tokens)** for stateless API authentication
- **Laravel Sanctum** for API token management
- **Firebase PHP-JWT** library for JWT encoding/decoding
- **Laravel Form Requests** for validation
- **Custom Exceptions** for consistent error handling

## Components

### 1. AuthService (`app/Services/Auth/AuthService.php`)

Core service handling all authentication operations:

#### Methods

- `login(email, password)`: Authenticates user and returns JWT token
- `register(data)`: Creates new user with role-specific profile
- `logout(userId)`: Invalidates all user tokens
- `refreshToken(token)`: Generates new JWT token
- `validateToken(token)`: Validates JWT token signature and expiration
- `getClaimsFromToken(token)`: Decodes JWT and returns token claims
- `generateJWT(user)`: Creates JWT token with user claims

#### Configuration

JWT settings are configured via environment variables:
```env
JWT_SECRET=your_jwt_secret_key_here
JWT_ALGORITHM=HS256
JWT_EXPIRATION=3600  # seconds
```

### 2. Custom Exceptions (`app/Exceptions/`)

- **AuthenticationException** (401): Invalid credentials or missing authentication
- **AuthorizationException** (403): Insufficient permissions
- **ResourceNotFoundException** (404): Requested resource not found
- **ValidationException** (422): Input validation failed with error details

### 3. Request Validation Classes (`app/Http/Requests/Auth/`)

#### LoginRequest
Validates login credentials:
- `email` (required, valid email, must exist in users table)
- `password` (required, min 6 characters)

#### RegisterRequest
Validates registration data:
- `email` (required, unique)
- `phone_number` (required, unique)
- `password` (required, min 8, confirmed)
- `first_name`, `last_name` (required)
- `role` (required, one of: admin, driver, student)
- Role-specific fields:
  - **Student**: `registration_number`, `department`, `year_of_study`
  - **Driver**: `license_number`, `license_class`, `license_expiry_date`
  - **Admin**: `designation`, `department`, `access_level`

#### ChangePasswordRequest
Validates password change:
- `current_password` (must match current password)
- `new_password` (min 8, confirmed, different from current)

#### UpdateUserRequest
Validates user profile updates:
- `email`, `phone_number` (optional, unique if provided)
- `first_name`, `last_name`, `is_active` (optional)

### 4. API Controllers

#### AuthController
Handles authentication operations:
- `POST /api/v1/auth/login` - User login
- `POST /api/v1/auth/register` - User registration
- `POST /api/v1/auth/logout` - User logout (requires auth)
- `POST /api/v1/auth/refresh` - Refresh JWT token (requires auth)
- `GET /api/v1/auth/me` - Get current user data (requires auth)

#### PasswordController
Handles password management:
- `POST /api/v1/auth/change-password` - Change password (requires auth)

#### UserController
Handles user management:
- `GET /api/v1/users` - List all users (admin only)
- `GET /api/v1/users/{id}` - Get user details
- `PUT /api/v1/users/{id}` - Update user (owner or admin)

### 5. API Response Helper (`app/Traits/ApiResponse.php`)

Provides consistent response format for all API endpoints:

```json
{
  "success": true/false,
  "message": "Response message",
  "data": {},
  "code": 200
}
```

Methods:
- `success(data, message, code)` - Success response
- `error(message, code, data)` - Error response
- `paginate(collection, message, code)` - Paginated response

## API Endpoints

### Authentication Endpoints

#### Login
```
POST /api/v1/auth/login
Content-Type: application/json

{
  "email": "user@example.com",
  "password": "password123"
}

Response (200):
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJhbGc...",
    "user": {
      "id": "uuid",
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "role": "student",
      "profile": { ... }
    }
  },
  "code": 200
}
```

#### Register
```
POST /api/v1/auth/register
Content-Type: application/json

{
  "email": "newuser@example.com",
  "phone_number": "+1234567890",
  "password": "SecurePass123",
  "password_confirmation": "SecurePass123",
  "first_name": "John",
  "last_name": "Doe",
  "role": "student",
  "registration_number": "STU001",
  "department": "Computer Science",
  "year_of_study": 2
}

Response (201):
{
  "success": true,
  "message": "Registration successful",
  "data": { ... },
  "code": 201
}
```

#### Logout
```
POST /api/v1/auth/logout
Authorization: Bearer {token}

Response (200):
{
  "success": true,
  "message": "Logout successful",
  "data": null,
  "code": 200
}
```

#### Refresh Token
```
POST /api/v1/auth/refresh
Authorization: Bearer {old_token}

Response (200):
{
  "success": true,
  "message": "Token refreshed",
  "data": {
    "token": "new_jwt_token",
    "user": { ... }
  },
  "code": 200
}
```

#### Get Current User
```
GET /api/v1/auth/me
Authorization: Bearer {token}

Response (200):
{
  "success": true,
  "message": "User data retrieved",
  "data": { ... },
  "code": 200
}
```

#### Change Password
```
POST /api/v1/auth/change-password
Authorization: Bearer {token}
Content-Type: application/json

{
  "current_password": "OldPassword123",
  "new_password": "NewPassword123",
  "new_password_confirmation": "NewPassword123"
}

Response (200):
{
  "success": true,
  "message": "Password changed successfully",
  "data": null,
  "code": 200
}
```

### User Management Endpoints

#### List Users (Admin Only)
```
GET /api/v1/users?per_page=15
Authorization: Bearer {token}

Response (200):
{
  "success": true,
  "message": "Users retrieved successfully",
  "data": [ { user1 }, { user2 }, ... ],
  "pagination": {
    "total": 100,
    "per_page": 15,
    "current_page": 1,
    "last_page": 7,
    "from": 1,
    "to": 15
  },
  "code": 200
}
```

#### Get User Details
```
GET /api/v1/users/{id}
Authorization: Bearer {token}

Response (200):
{
  "success": true,
  "message": "User retrieved successfully",
  "data": { ... },
  "code": 200
}
```

#### Update User
```
PUT /api/v1/users/{id}
Authorization: Bearer {token}
Content-Type: application/json

{
  "email": "newemail@example.com",
  "first_name": "Jane",
  "is_active": true
}

Response (200):
{
  "success": true,
  "message": "User updated successfully",
  "data": { ... },
  "code": 200
}
```

## Authentication Flow

### JWT Token Flow

1. **Login/Register**: User sends credentials
2. **Generate JWT**: AuthService creates JWT with user claims
3. **Token Response**: Token returned to client
4. **Include in Requests**: Client includes token in Authorization header
5. **Validate**: Middleware validates token using JWT_SECRET
6. **Extract Claims**: Authenticated request has access to user data

### Token Structure

JWT tokens contain:
```json
{
  "iss": "http://localhost",
  "sub": "user_id",
  "iat": 1234567890,
  "exp": 1234571490,
  "user_id": "uuid",
  "email": "user@example.com",
  "role": "student"
}
```

### Authorization Checks

- **Public Routes**: `/auth/login`, `/auth/register`
- **Protected Routes**: Require valid JWT token in Authorization header
- **Admin Routes**: `/users` list requires admin role
- **Owner-based Routes**: `/users/{id}` allows owner or admin

## Error Handling

### Standard Error Response Format

```json
{
  "success": false,
  "message": "Error description",
  "data": null,
  "code": 400
}
```

### HTTP Status Codes

- `200` - Success
- `201` - Created
- `401` - Unauthorized (invalid/missing credentials)
- `403` - Forbidden (insufficient permissions)
- `404` - Not Found
- `422` - Validation Failed

### Validation Error Response

```json
{
  "success": false,
  "message": "Validation failed",
  "data": {
    "email": ["Email is required"],
    "password": ["Password must be at least 8 characters"]
  },
  "code": 422
}
```

## Usage Examples

### Client-side Implementation

#### Using JavaScript/Axios

```javascript
const axios = require('axios');

const api = axios.create({
  baseURL: 'http://localhost:80/api/v1',
});

// Login
async function login(email, password) {
  try {
    const response = await api.post('/auth/login', { email, password });
    localStorage.setItem('token', response.data.data.token);
    api.defaults.headers.common['Authorization'] = 
      `Bearer ${response.data.data.token}`;
    return response.data.data;
  } catch (error) {
    console.error('Login failed:', error.response.data);
  }
}

// Make authenticated request
async function getCurrentUser() {
  try {
    const response = await api.get('/auth/me');
    return response.data.data;
  } catch (error) {
    console.error('Failed to get user:', error.response.data);
  }
}

// Logout
async function logout() {
  try {
    await api.post('/auth/logout');
    localStorage.removeItem('token');
    delete api.defaults.headers.common['Authorization'];
  } catch (error) {
    console.error('Logout failed:', error.response.data);
  }
}
```

#### Using cURL

```bash
# Login
curl -X POST http://localhost:80/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password123"}'

# Get user with token
curl -X GET http://localhost:80/api/v1/auth/me \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"

# Logout
curl -X POST http://localhost:80/api/v1/auth/logout \
  -H "Authorization: Bearer YOUR_JWT_TOKEN"
```

## File Structure

```
app/
├── Exceptions/
│   ├── AuthenticationException.php
│   ├── AuthorizationException.php
│   ├── ResourceNotFoundException.php
│   └── ValidationException.php
├── Http/
│   ├── Controllers/Api/
│   │   ├── AuthController.php
│   │   ├── PasswordController.php
│   │   └── UserController.php
│   └── Requests/Auth/
│       ├── LoginRequest.php
│       ├── RegisterRequest.php
│       ├── ChangePasswordRequest.php
│       └── UpdateUserRequest.php
├── Services/Auth/
│   └── AuthService.php
└── Traits/
    └── ApiResponse.php
routes/
└── api.php

config/
└── auth.php (JWT configuration)
```

## Security Considerations

1. **JWT Secret**: Keep `JWT_SECRET` secure in production (use strong random value)
2. **Token Expiration**: Set appropriate expiration time based on security needs
3. **HTTPS**: Always use HTTPS in production
4. **Password Hashing**: Passwords are hashed using Laravel's Hash facade (bcrypt)
5. **Token Validation**: Tokens are validated on every protected request
6. **CORS**: Configure CORS appropriately for frontend integration

## Testing the Authentication System

### 1. Test User Registration

```bash
curl -X POST http://localhost:80/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "student@example.com",
    "phone_number": "+1234567890",
    "password": "SecurePass123",
    "password_confirmation": "SecurePass123",
    "first_name": "John",
    "last_name": "Doe",
    "role": "student",
    "registration_number": "STU001",
    "department": "CS"
  }'
```

### 2. Test User Login

```bash
curl -X POST http://localhost:80/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "student@example.com",
    "password": "SecurePass123"
  }'
```

### 3. Test Protected Route

```bash
curl -X GET http://localhost:80/api/v1/auth/me \
  -H "Authorization: Bearer <your-jwt-token>"
```

## Environment Setup

Ensure these values are set in your `.env` file:

```env
JWT_SECRET=your_very_secure_random_string_here_minimum_32_chars
JWT_ALGORITHM=HS256
JWT_EXPIRATION=3600
```

For development, you can generate a random secret:
```bash
php -r 'echo bin2hex(random_bytes(32));'
```

---

**Last Updated**: 2024
**Version**: 1.0
