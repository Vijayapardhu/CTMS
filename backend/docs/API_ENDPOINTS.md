# CTMS API Documentation Scaffold (v1)

## Base URL
`http://localhost:80/api/v1`

---

## Authentication Endpoints

### 1. Login User
- **URL:** `POST /auth/login`
- **Headers:** `Content-Type: application/json`
- **Request Body:**
```json
{
  "email": "user@example.com",
  "password": "Password123!"
}
```
- **Response (200 OK):**
```json
{
  "success": true,
  "message": "Login successful",
  "data": {
    "token": "eyJhbGciOiJIUzI1Ni...",
    "token_type": "Bearer",
    "expires_in": 3600,
    "user": {
      "id": "9b1deb4d-3b7d-41b9-9e20-7f2874136e05",
      "email": "user@example.com",
      "first_name": "John",
      "last_name": "Doe",
      "role": "STUDENT"
    }
  },
  "code": 200
}
```

### 2. Register User
- **URL:** `POST /auth/register`
- **Request Body:**
```json
{
  "email": "student@ctms.edu",
  "phone_number": "+1987654321",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "first_name": "Jane",
  "last_name": "Smith",
  "role": "STUDENT",
  "registration_number": "REG99881",
  "department": "Computer Science",
  "year_of_study": 3
}
```

### 3. Logout User
- **URL:** `POST /auth/logout`
- **Headers:** `Authorization: Bearer <token>`

### 4. Refresh Token
- **URL:** `POST /auth/refresh`
- **Headers:** `Authorization: Bearer <token>`

### 5. Profile Details
- **URL:** `GET /auth/me`
- **Headers:** `Authorization: Bearer <token>`

---

## User Management Endpoints (Admin Only)

### 1. List Users
- **URL:** `GET /users?page=1&per_page=15`
- **Headers:** `Authorization: Bearer <admin_token>`

### 2. Get User Details
- **URL:** `GET /users/{id}`
- **Headers:** `Authorization: Bearer <token>`

### 3. Update User
- **URL:** `PUT /users/{id}`
- **Headers:** `Authorization: Bearer <token>`
