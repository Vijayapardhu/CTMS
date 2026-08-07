# Quick Start Guide - CTMS Authentication System

This guide will help you quickly get started with the CTMS authentication system.

## Prerequisites

Ensure these environment variables are set in your `.env` file:

```env
JWT_SECRET=your_jwt_secret_key_here_change_in_production
JWT_ALGORITHM=HS256
JWT_EXPIRATION=3600
```

## Basic Usage

### 1. Register a Student

```bash
curl -X POST http://localhost:80/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john.doe@university.edu",
    "phone_number": "+1234567890",
    "password": "SecurePass123",
    "password_confirmation": "SecurePass123",
    "first_name": "John",
    "last_name": "Doe",
    "role": "student",
    "registration_number": "STU001",
    "department": "Computer Science",
    "year_of_study": 2
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Registration successful",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": {
      "id": "550e8400-e29b-41d4-a716-446655440000",
      "email": "john.doe@university.edu",
      "phone_number": "+1234567890",
      "first_name": "John",
      "last_name": "Doe",
      "full_name": "John Doe",
      "role": "student",
      "is_active": true,
      "last_login_at": null,
      "profile": {
        "id": "...",
        "registration_number": "STU001",
        "department": "Computer Science",
        "year_of_study": 2,
        "has_valid_ticket": false,
        "ticket_expiry_date": null
      }
    }
  },
  "code": 201
}
```

### 2. Register a Driver

```bash
curl -X POST http://localhost:80/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "driver@university.edu",
    "phone_number": "+1987654321",
    "password": "SecurePass123",
    "password_confirmation": "SecurePass123",
    "first_name": "Mike",
    "last_name": "Driver",
    "role": "driver",
    "license_number": "DRV123456",
    "license_class": "A",
    "license_expiry_date": "2026-12-31"
  }'
```

### 3. Register an Admin

```bash
curl -X POST http://localhost:80/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "admin@university.edu",
    "phone_number": "+1555555555",
    "password": "SecurePass123",
    "password_confirmation": "SecurePass123",
    "first_name": "Admin",
    "last_name": "User",
    "role": "admin",
    "designation": "System Administrator",
    "department": "IT",
    "access_level": "full"
  }'
```

### 4. Login

```bash
curl -X POST http://localhost:80/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "john.doe@university.edu",
    "password": "SecurePass123"
  }'
```

**Response:**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...",
    "user": { ... }
  },
  "code": 200
}
```

### 5. Use Token for Authenticated Requests

Save the token from login/register response and include it in subsequent requests:

```bash
# Get current user
curl -X GET http://localhost:80/api/v1/auth/me \
  -H "Authorization: Bearer <your-jwt-token-here>"

# Change password
curl -X POST http://localhost:80/api/v1/auth/change-password \
  -H "Authorization: Bearer <your-jwt-token-here>" \
  -H "Content-Type: application/json" \
  -d '{
    "current_password": "SecurePass123",
    "new_password": "NewPass456",
    "new_password_confirmation": "NewPass456"
  }'

# List users (admin only)
curl -X GET http://localhost:80/api/v1/users \
  -H "Authorization: Bearer <admin-token>"

# Get specific user
curl -X GET http://localhost:80/api/v1/users/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer <your-token>"

# Update user
curl -X PUT http://localhost:80/api/v1/users/550e8400-e29b-41d4-a716-446655440000 \
  -H "Authorization: Bearer <your-token>" \
  -H "Content-Type: application/json" \
  -d '{
    "first_name": "Johnny",
    "email": "johnny.doe@university.edu"
  }'
```

### 6. Logout

```bash
curl -X POST http://localhost:80/api/v1/auth/logout \
  -H "Authorization: Bearer <your-jwt-token-here>"
```

### 7. Refresh Token

```bash
curl -X POST http://localhost:80/api/v1/auth/refresh \
  -H "Authorization: Bearer <your-jwt-token-here>"
```

## Frontend Integration Example (JavaScript/Axios)

```javascript
import axios from 'axios';

// Create API instance
const api = axios.create({
  baseURL: 'http://localhost:80/api/v1',
});

// Add token to headers
function setAuthToken(token) {
  if (token) {
    api.defaults.headers.common['Authorization'] = `Bearer ${token}`;
    localStorage.setItem('auth_token', token);
  } else {
    delete api.defaults.headers.common['Authorization'];
    localStorage.removeItem('auth_token');
  }
}

// Initialize with stored token if available
const storedToken = localStorage.getItem('auth_token');
if (storedToken) {
  setAuthToken(storedToken);
}

// Login
async function login(email, password) {
  try {
    const response = await api.post('/auth/login', { email, password });
    setAuthToken(response.data.data.token);
    return response.data.data.user;
  } catch (error) {
    console.error('Login failed:', error.response?.data?.message);
    throw error;
  }
}

// Register
async function register(userData) {
  try {
    const response = await api.post('/auth/register', userData);
    setAuthToken(response.data.data.token);
    return response.data.data.user;
  } catch (error) {
    console.error('Registration failed:', error.response?.data?.message);
    throw error;
  }
}

// Get current user
async function getCurrentUser() {
  try {
    const response = await api.get('/auth/me');
    return response.data.data;
  } catch (error) {
    console.error('Failed to get user:', error.response?.data?.message);
    throw error;
  }
}

// Change password
async function changePassword(currentPassword, newPassword) {
  try {
    await api.post('/auth/change-password', {
      current_password: currentPassword,
      new_password: newPassword,
      new_password_confirmation: newPassword,
    });
    return true;
  } catch (error) {
    console.error('Failed to change password:', error.response?.data?.message);
    throw error;
  }
}

// Logout
async function logout() {
  try {
    await api.post('/auth/logout');
    setAuthToken(null);
    return true;
  } catch (error) {
    console.error('Logout failed:', error.response?.data?.message);
    setAuthToken(null);
    return false;
  }
}

// Get users (admin only)
async function getUsers(page = 1, perPage = 15) {
  try {
    const response = await api.get(`/users?page=${page}&per_page=${perPage}`);
    return response.data;
  } catch (error) {
    console.error('Failed to get users:', error.response?.data?.message);
    throw error;
  }
}

// Get user by ID
async function getUser(id) {
  try {
    const response = await api.get(`/users/${id}`);
    return response.data.data;
  } catch (error) {
    console.error('Failed to get user:', error.response?.data?.message);
    throw error;
  }
}

// Update user
async function updateUser(id, userData) {
  try {
    const response = await api.put(`/users/${id}`, userData);
    return response.data.data;
  } catch (error) {
    console.error('Failed to update user:', error.response?.data?.message);
    throw error;
  }
}

export {
  login,
  register,
  getCurrentUser,
  changePassword,
  logout,
  getUsers,
  getUser,
  updateUser,
  api,
};
```

## User Roles and Permissions

### Student
- Can view own profile
- Can change password
- Cannot view other users
- Cannot manage users

### Driver
- Can view own profile
- Can change password
- Cannot view other users
- Cannot manage users

### Admin
- Can view all users
- Can view user details
- Can update any user
- Can change password

## Common Errors and Solutions

### Invalid Credentials
```json
{
  "success": false,
  "message": "Invalid email or password",
  "code": 401
}
```
**Solution**: Check email and password are correct.

### Email Already Registered
```json
{
  "success": false,
  "message": "Validation failed",
  "data": {
    "email": ["This email is already registered"]
  },
  "code": 422
}
```
**Solution**: Use a different email address.

### Invalid Token
```json
{
  "success": false,
  "message": "Invalid token: ...",
  "code": 401
}
```
**Solution**: Ensure token is valid and not expired. Get a new token by logging in again.

### Unauthorized Access
```json
{
  "success": false,
  "message": "Unauthorized to view this user",
  "code": 403
}
```
**Solution**: Only admins can view other users' details. Users can only view their own profile.

### Token Expired
```json
{
  "success": false,
  "message": "Invalid token: ...",
  "code": 401
}
```
**Solution**: Use the refresh endpoint to get a new token, or login again.

## Testing with Postman

1. Create a new collection
2. Add environment variables:
   - `base_url`: `http://localhost:80/api/v1`
   - `token`: (will be populated after login)
3. Create requests:
   - **Register**: `POST {{base_url}}/auth/register`
   - **Login**: `POST {{base_url}}/auth/login`
   - In login response, copy token and set `{{token}}` environment variable
   - **Get Me**: `GET {{base_url}}/auth/me` with header `Authorization: Bearer {{token}}`

## API Response Format

All API responses follow this format:

```json
{
  "success": boolean,
  "message": "Response message",
  "data": null | object | array,
  "code": HTTP_status_code
}
```

## HTTP Status Codes

- `200`: Success
- `201`: Created
- `400`: Bad Request
- `401`: Unauthorized (invalid credentials/token)
- `403`: Forbidden (insufficient permissions)
- `404`: Not Found
- `422`: Validation Failed
- `500`: Server Error

## Next Steps

1. Read the full [AUTHENTICATION.md](AUTHENTICATION.md) for comprehensive documentation
2. Review example files in the codebase
3. Test all endpoints with curl or Postman
4. Integrate with your frontend application

---

**Need Help?** Check the AUTHENTICATION.md file for detailed documentation or review the source files in:
- `app/Services/Auth/AuthService.php` - Authentication logic
- `app/Http/Controllers/Api/AuthController.php` - Auth endpoints
- `app/Http/Controllers/Api/UserController.php` - User management
