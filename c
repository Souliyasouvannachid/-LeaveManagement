# Leave Management System — REST API Documentation

## Overview

ระบบ API สำหรับจัดการการลาพักรบบนโมเดล MVC แบบ PHP純แบบ (Pure PHP) ที่ใช้ฐานข้อมูล MySQL (ผ่าน XAMPP)

API นี้เป็น **backend** ที่ใหม่ที่ถูกสร้างขึ้นมา โดยย้าย business logic ที่มีอยู่ใน folder `public/` (เช่น `create.php`, `approvals.php`, `my_requests.php`) มาเป็น RESTful API ที่ใช้ JSON

---

## ไฟล์ที่สร้าง

| ไฟล์ | หน้าที่ |
|---|---|
| `api/index.php` | Router หลัก (เข้าถึงผ่าน `/api/`) |
| `api/helpers.php` | Helper functions (json_response, token, leave balance ฯลึก) |
| `api/BaseController.php` | คลาสพื้นฐานของทุก controller |
| `api/AuthMiddleware.php` | ตรวจสอบ Bearer Token + โหลดข้อมูลผู้ใช้ |
| `api/AuthController.php` | เข้าสู่ระบบ + ออก token |
| `api/LeaveRequestController.php` | สร้าง/แสดง/ยกเลิด คำขอลา |
| `api/ApprovalController.php` | รายการรออนุมัติ / อนุมัติ / ปฏิเสธ |
| `api/LeaveBalanceController.php` | ดูยอดวันลาคงเหลือ |
| `api/NotificationController.php` | จัดการการแจ้งเตือน |
| `api/HolidayController.php` | ดูวันหยุด |

---

## วิธีการใช้งาน

### 1. เข้าสู่ระบบและขอ token

```
POST /api/auth/login
Content-Type: application/json

{
    "email": "user@example.com",
    "password": "password123"
}
```

**Response (200):**
```json
{
    "success": true,
    "message": "เข้าสู่ระบบสำเร็จ",
    "data": {
        "token": "eyJ...signature",
        "token_type": "Bearer",
        "expires_in": 3600,
        "user": {
            "id": 1,
            "employee_code": "EMP001",
            "name": "John Doe",
            "email": "john@example.com",
            "role": "employee",
            "department_id": 5
        }
    }
}
```

### 2. ใช้ token ในการเข้าถึง API

ทุก request หลัง login ต้องมี header:
```
Authorization: Bearer {token}
```

---

## เส้นทาง API (Endpoints)

### Auth
| Method | Endpoint | Public | คำอธิบาย |
|--------|----------|--------|----------|
| POST | `/api/auth/login` | ✅ | เข้าสู่ระบบ ออก token |

### Leave Requests (คำขอลา)
| Method | Endpoint | คำอธิบาย |
|--------|----------|----------|
| GET | `/api/leave-requests` | รายชื่อคำขอลา (ของผู้ใช้ / ทีมตาม role) |
| GET | `/api/leave-requests?year=2025` | กรองตามปี |
| POST | `/api/leave-requests` | สร้างคำขอลาใหม่ |
| GET | `/api/leave-requests/{id}` | ดูรายละเอียดคำขอลา |
| POST | `/api/leave-requests/{id}/cancel` | ยกเลิดคำขอลา (เฉพาะเจ้าของ + สถานะ Pending) |

### Approvals (การอนุมัติ)
| Method | Endpoint | คำอธิบาย |
|--------|----------|----------|
| GET | `/api/approvals` | รายการคำขอลาที่รออนุมัติ (เฉพาะ manager/admin) |
| POST | `/api/approvals/{id}/approve` | อนุมัติคำขอลา |
| POST | `/api/approvals/{id}/reject` | ปฏิเสธคำขอลา (ต้องระบุ `manager_comment`) |

### Leave Balances (ยอดวันลา)
| Method | Endpoint | คำอธิบาย |
|--------|----------|----------|
| GET | `/api/leave-balances` | ยอดวันลาปีปัจจุบัน |
| GET | `/api/leave-balances/{year}` | ยอดวันลาตามปี |

### Notifications (การแจ้งเตือน)
| Method | Endpoint | คำอธิบาย |
|--------|----------|----------|
| GET | `/api/notifications` | แจ้งเตือนทั้งหมด |
| GET | `/api/notifications/unread` | แจ้งเตือนที่ยังไม่ได้อ่าน |
| POST | `/api/notifications/{id}/read` | ทำเครื่องหมายว่าอ่านแล้ว |
| POST | `/api/notifications/{id}/unread` | ทำเครื่องหมายว่ายังไม่อ่าน |

### Holidays (วันหยุด)
| Method | Endpoint | คำอธิบาย |
|--------|----------|----------|
| GET | `/api/holidays` | รายชื่อวันหยุดทั้งหมด |
| GET | `/api/holidays/2025` | วันหยุดปี 2025 |

---

## ตัวอย่างการใช้งาน (cURL)

### เข้าสู่ระบบ
```bash
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"john@example.com","password":"password123"}'
```

### ดูคำขอลาทั้งหมด
```bash
curl http://localhost:8080/api/leave-requests \
  -H "Authorization: Bearer {token}"
```

### สร้างคำขอลาใหม่
```bash
curl -X POST http://localhost:8080/api/leave-requests \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "leave_type_id": 1,
    "start_date": "2025-01-20",
    "end_date": "2025-01-21",
    "period": "full_day",
    "reason": "ไปโรงพยาบาล"
  }'
```

### อนุมัติคำขอลา
```bash
curl -X POST http://localhost:8080/api/approvals/12/approve \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"manager_comment": "ผ่านค่ะ"}'
```

---

## โครงสร้างฐานข้อมูล (Tables ที่ใช้)

API นี้ใช้ตารางต่อไปนี้จาก `database/database.sql`:
- `users` — ผู้ใช้ระบบ
- `leave_types` — ประเภทวันลา
- `leave_requests` — คำขอลา
- `leave_balances` — ยอดวันลาคงเหลือ
- `holidays` — วันหยุด
- `departments` — แผนก
- `notifications` — การแจ้งเตือน
- `audit_logs` — บันทึกกระบวนการทำงาน

---

## การตั้งค่าเพิ่มเติม

- **Secret key** สำหรับ token: ตั้งค่าผ่าน environment variable `API_TOKEN_SECRET` หรือใช้ค่าเริ่มต้น `"leave-management-secret"`
- **Token หมดอายุ**: 1 ชั่วโมง (3600 วินาที)
- **CORS**: รองรับทุก domain (`Access-Control-Allow-Origin: *`)

---

## หมายเหตุ

- ทุก endpoint ที่ไม่ใช่ `/api/auth/login` ต้องมีการ authed ผ่าน Bearer Token
- ผู้ใช้ role `employee` มองเห็นคำขอลาของตัวเองเท่านั้น
- ผู้ใช้ role `manager` มองเห็นคำขอลาของทีม + สามารถอนุมัติ/ปฏิเสธ
- ผู้ใช้ role `admin` มีสิทธิ์เต็ม
