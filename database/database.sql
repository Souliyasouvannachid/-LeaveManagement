-- ຖານຂໍ້ມູນລະບົບຈັດການການລາພັກ
-- MySQL 8+
-- ລະຫັດຜ່ານທົດລອງສຳລັບຜູ້ໃຊ້ຕົວຢ່າງ: password123

SET NAMES utf8mb4;
SET time_zone = '+07:00';
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS leave_management
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE leave_management;

DROP TABLE IF EXISTS audit_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS leave_requests;
DROP TABLE IF EXISTS leave_balances;
DROP TABLE IF EXISTS holidays;
DROP TABLE IF EXISTS leave_types;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS positions;
DROP TABLE IF EXISTS departments;
DROP TABLE IF EXISTS settings;

CREATE TABLE departments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  description TEXT NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_departments_name (name),
  KEY idx_departments_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE positions (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  department_id BIGINT UNSIGNED NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_positions_department_id (department_id),
  KEY idx_positions_status (status),
  UNIQUE KEY uq_positions_department_name (department_id, name),
  CONSTRAINT fk_positions_department
    FOREIGN KEY (department_id) REFERENCES departments(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  employee_code VARCHAR(50) NOT NULL,
  first_name VARCHAR(100) NOT NULL,
  last_name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(30) NULL,
  avatar VARCHAR(255) NULL,
  role ENUM('admin', 'hr', 'manager', 'employee') NOT NULL DEFAULT 'employee',
  department_id BIGINT UNSIGNED NULL,
  position_id BIGINT UNSIGNED NULL,
  manager_id BIGINT UNSIGNED NULL,
  start_date DATE NULL,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_employee_code (employee_code),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_status (status),
  KEY idx_users_department_id (department_id),
  KEY idx_users_position_id (position_id),
  KEY idx_users_manager_id (manager_id),
  CONSTRAINT fk_users_department
    FOREIGN KEY (department_id) REFERENCES departments(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_users_position
    FOREIGN KEY (position_id) REFERENCES positions(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_users_manager
    FOREIGN KEY (manager_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  code VARCHAR(30) NOT NULL,
  description TEXT NULL,
  annual_quota DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  is_paid TINYINT(1) NOT NULL DEFAULT 1,
  allow_half_day TINYINT(1) NOT NULL DEFAULT 1,
  require_attachment TINYINT(1) NOT NULL DEFAULT 0,
  min_notice_days INT UNSIGNED NOT NULL DEFAULT 0,
  max_days_per_request DECIMAL(5,2) NULL,
  color VARCHAR(20) NOT NULL DEFAULT '#0d6efd',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leave_types_code (code),
  KEY idx_leave_types_status (status),
  KEY idx_leave_types_require_attachment (require_attachment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE holidays (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  holiday_date DATE NOT NULL,
  type ENUM('company', 'public', 'special') NOT NULL DEFAULT 'public',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_holidays_date_name (holiday_date, name),
  KEY idx_holidays_date (holiday_date),
  KEY idx_holidays_type (type),
  KEY idx_holidays_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_balances (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  year YEAR NOT NULL,
  entitled_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  used_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  pending_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  remaining_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  adjusted_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leave_balances_user_type_year (user_id, leave_type_id, year),
  KEY idx_leave_balances_year (year),
  KEY idx_leave_balances_leave_type_id (leave_type_id),
  CONSTRAINT fk_leave_balances_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_leave_balances_leave_type
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT chk_leave_balances_non_negative
    CHECK (entitled_days >= 0 AND used_days >= 0 AND pending_days >= 0 AND remaining_days >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_requests (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  request_no VARCHAR(30) NOT NULL,
  user_id BIGINT UNSIGNED NOT NULL,
  leave_type_id BIGINT UNSIGNED NOT NULL,
  start_date DATE NOT NULL,
  end_date DATE NOT NULL,
  start_period ENUM('full_day', 'morning', 'afternoon') NOT NULL DEFAULT 'full_day',
  end_period ENUM('full_day', 'morning', 'afternoon') NOT NULL DEFAULT 'full_day',
  total_days DECIMAL(6,2) NOT NULL DEFAULT 0.00,
  reason TEXT NOT NULL,
  handover_to_user_id BIGINT UNSIGNED NULL,
  attachment VARCHAR(255) NULL,
  status ENUM('Draft', 'Pending', 'Approved', 'Rejected', 'Cancelled') NOT NULL DEFAULT 'Draft',
  manager_id BIGINT UNSIGNED NULL,
  manager_comment TEXT NULL,
  approved_at DATETIME NULL,
  rejected_at DATETIME NULL,
  cancelled_at DATETIME NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leave_requests_request_no (request_no),
  KEY idx_leave_requests_user_id (user_id),
  KEY idx_leave_requests_leave_type_id (leave_type_id),
  KEY idx_leave_requests_status (status),
  KEY idx_leave_requests_manager_status (manager_id, status),
  KEY idx_leave_requests_date_range (start_date, end_date),
  KEY idx_leave_requests_handover_to_user_id (handover_to_user_id),
  CONSTRAINT fk_leave_requests_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_leave_requests_leave_type
    FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_leave_requests_handover_user
    FOREIGN KEY (handover_to_user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_leave_requests_manager
    FOREIGN KEY (manager_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT chk_leave_requests_date_order
    CHECK (end_date >= start_date),
  CONSTRAINT chk_leave_requests_total_days
    CHECK (total_days >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NOT NULL,
  title VARCHAR(180) NOT NULL,
  message TEXT NOT NULL,
  link VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_notifications_user_read (user_id, is_read),
  KEY idx_notifications_created_at (created_at),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id BIGINT UNSIGNED NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id BIGINT UNSIGNED NULL,
  old_value JSON NULL,
  new_value JSON NULL,
  ip_address VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_audit_logs_user_id (user_id),
  KEY idx_audit_logs_action (action),
  KEY idx_audit_logs_entity (entity_type, entity_id),
  KEY idx_audit_logs_created_at (created_at),
  CONSTRAINT fk_audit_logs_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE settings (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  description VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_settings_key (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO departments (id, name, description, status) VALUES
(1, 'ຜູ້ບໍລິຫານ', 'ຝ່າຍບໍລິຫານອົງກອນ', 'active'),
(2, 'ຊັບພະຍາກອນມະນຸດ', 'ດູແລຂໍ້ມູນພະນັກງານ ແລະ ສະຫວັດດີການ', 'active'),
(3, 'ເຕັກໂນໂລຊີຂໍ້ມູນຂ່າວສານ', 'ພັດທະນາລະບົບ ແລະ ດູແລໂຄງສ້າງພື້ນຖານ', 'active'),
(4, 'ການເງິນ ແລະ ບັນຊີ', 'ດູແລບັນຊີ ການເງິນ ແລະ ງົບປະມານ', 'active'),
(5, 'ຂາຍ ແລະ ການຕະຫຼາດ', 'ດູແລລູກຄ້າ ຍອດຂາຍ ແລະ ສື່ສານການຕະຫຼາດ', 'active');

INSERT INTO positions (id, name, department_id, status) VALUES
(1, 'ຜູ້ດູແລລະບົບ', 1, 'active'),
(2, 'ເຈົ້າໜ້າທີ່ບຸກຄະລາກອນ', 2, 'active'),
(3, 'ຜູ້ຈັດການດ້ານໄອທີ', 3, 'active'),
(4, 'ນັກພັດທະນາຊອບແວ', 3, 'active'),
(5, 'ນັກບັນຊີ', 4, 'active'),
(6, 'ພະນັກງານຂາຍ', 5, 'active');

-- password_hash('password123', PASSWORD_BCRYPT)
INSERT INTO users (
  id, employee_code, first_name, last_name, email, password, phone, role,
  department_id, position_id, manager_id, start_date, status
) VALUES
(1, 'EMP-0001', 'ສຸລິຍາ', 'ສຸວັນນະຈິດ', 'admin@example.com', '$2y$10$u/qww2QekDkK7TdbvP2sVeQIFbuLfY1Mz/45FKSwS.6rIYeU1PkDi', '020000001', 'admin', 1, 1, NULL, '2024-01-01', 'active'),
(2, 'EMP-0002', 'ຫະໄທຊົນກະ', 'ບຸນມາກ', 'hr@example.com', '$2y$10$u/qww2QekDkK7TdbvP2sVeQIFbuLfY1Mz/45FKSwS.6rIYeU1PkDi', '020000002', 'hr', 2, 2, 1, '2024-01-15', 'active'),
(3, 'EMP-0003', 'ກິດຕິພົງ', 'ສີສຸກ', 'manager@example.com', '$2y$10$u/qww2QekDkK7TdbvP2sVeQIFbuLfY1Mz/45FKSwS.6rIYeU1PkDi', '020000003', 'manager', 3, 3, 1, '2024-02-01', 'active'),
(4, 'EMP-0004', 'ນັດຖະວຸດ', 'ໃຈດີ', 'employee@example.com', '$2y$10$u/qww2QekDkK7TdbvP2sVeQIFbuLfY1Mz/45FKSwS.6rIYeU1PkDi', '020000004', 'employee', 3, 4, 3, '2024-03-01', 'active'),
(5, 'EMP-0005', 'ມິນຕຣາ', 'ແສງທອງ', 'mintra@example.com', '$2y$10$u/qww2QekDkK7TdbvP2sVeQIFbuLfY1Mz/45FKSwS.6rIYeU1PkDi', '020000005', 'employee', 4, 5, 1, '2024-04-01', 'active'),
(6, 'EMP-0006', 'ທະນະກອນ', 'ດີພ້ອມ', 'thanakorn@example.com', '$2y$10$u/qww2QekDkK7TdbvP2sVeQIFbuLfY1Mz/45FKSwS.6rIYeU1PkDi', '020000006', 'employee', 5, 6, 1, '2024-05-01', 'active');

INSERT INTO leave_types (
  id, name, code, description, annual_quota, is_paid, allow_half_day,
  require_attachment, min_notice_days, max_days_per_request, color, status
) VALUES
(1, 'ລາພັກຮ້ອນ', 'ANNUAL', 'ວັນລາພັກຜ່ອນປະຈຳປີ', 10.00, 1, 1, 0, 3, 5.00, '#0d6efd', 'active'),
(2, 'ລາປ່ວຍ', 'SICK', 'ວັນລາປ່ວຍ ຕ້ອງແນບໃບຮັບຮອງແພດເມື່ອຈຳເປັນ', 30.00, 1, 1, 1, 0, 30.00, '#dc3545', 'active'),
(3, 'ລາກິດ', 'PERSONAL', 'ວັນລາກິດສ່ວນຕົວ', 6.00, 1, 1, 0, 1, 3.00, '#ffc107', 'active'),
(4, 'ລາຄອດ', 'MATERNITY', 'ວັນລາຄອດຕາມນະໂຍບາຍບໍລິສັດ', 98.00, 1, 0, 1, 30, 98.00, '#d63384', 'active'),
(5, 'ລາບໍ່ຮັບຄ່າຈ້າງ', 'UNPAID', 'ວັນລາແບບບໍ່ຮັບຄ່າຈ້າງ', 0.00, 0, 1, 0, 7, NULL, '#6c757d', 'active');

INSERT INTO holidays (id, name, holiday_date, type, status) VALUES
(1, 'ວັນຂຶ້ນປີໃໝ່', '2026-01-01', 'public', 'active'),
(2, 'ວັນສົງການ', '2026-04-13', 'public', 'active'),
(3, 'ວັນສົງການ', '2026-04-14', 'public', 'active'),
(4, 'ວັນສົງການ', '2026-04-15', 'public', 'active'),
(5, 'ວັນອອກແຮງງານແຫ່ງຊາດ', '2026-05-01', 'public', 'active'),
(6, 'ວັນສະເຫຼີມພະຊົນມະພັນສາ ຣ.10', '2026-07-28', 'public', 'active'),
(7, 'ວັນພັກບໍລິສັດປະຈຳປີ', '2026-12-30', 'company', 'active'),
(8, 'ວັນສິ້ນປີ', '2026-12-31', 'public', 'active');

INSERT INTO leave_balances (
  user_id, leave_type_id, year, entitled_days, used_days, pending_days, remaining_days, adjusted_days
) VALUES
(1, 1, 2026, 10.00, 0.00, 0.00, 10.00, 0.00),
(1, 2, 2026, 30.00, 0.00, 0.00, 30.00, 0.00),
(1, 3, 2026, 6.00, 0.00, 0.00, 6.00, 0.00),
(2, 1, 2026, 10.00, 1.00, 0.00, 9.00, 0.00),
(2, 2, 2026, 30.00, 0.00, 0.00, 30.00, 0.00),
(2, 3, 2026, 6.00, 0.00, 0.00, 6.00, 0.00),
(3, 1, 2026, 10.00, 2.00, 0.00, 8.00, 0.00),
(3, 2, 2026, 30.00, 0.00, 0.00, 30.00, 0.00),
(3, 3, 2026, 6.00, 0.00, 0.00, 6.00, 0.00),
(4, 1, 2026, 10.00, 1.00, 1.00, 8.00, 0.00),
(4, 2, 2026, 30.00, 0.00, 0.00, 30.00, 0.00),
(4, 3, 2026, 6.00, 0.00, 0.00, 6.00, 0.00),
(5, 1, 2026, 10.00, 0.00, 0.00, 10.00, 0.00),
(5, 2, 2026, 30.00, 0.00, 0.00, 30.00, 0.00),
(5, 3, 2026, 6.00, 0.00, 0.00, 6.00, 0.00),
(6, 1, 2026, 10.00, 0.00, 0.00, 10.00, 0.00),
(6, 2, 2026, 30.00, 0.00, 0.00, 30.00, 0.00),
(6, 3, 2026, 6.00, 0.00, 0.00, 6.00, 0.00);

INSERT INTO leave_requests (
  id, request_no, user_id, leave_type_id, start_date, end_date, start_period, end_period,
  total_days, reason, handover_to_user_id, attachment, status, manager_id,
  manager_comment, approved_at, rejected_at, cancelled_at, created_at
) VALUES
(1, 'LV-2026-000001', 4, 1, '2026-05-04', '2026-05-04', 'full_day', 'full_day',
 1.00, 'ພັກຜ່ອນປະຈຳປີ', 5, NULL, 'Approved', 3, 'ອະນຸມັດ', '2026-04-20 09:30:00', NULL, NULL, '2026-04-19 14:10:00'),
(2, 'LV-2026-000002', 4, 3, '2026-05-08', '2026-05-08', 'full_day', 'full_day',
 1.00, 'ຕິດຕໍ່ທຸລະສ່ວນຕົວ', 5, NULL, 'Pending', 3, NULL, NULL, NULL, NULL, '2026-04-28 10:15:00'),
(3, 'LV-2026-000003', 2, 1, '2026-03-10', '2026-03-10', 'full_day', 'full_day',
 1.00, 'ພັກຜ່ອນກັບຄອບຄົວ', NULL, NULL, 'Approved', 1, 'ອະນຸມັດ', '2026-03-05 11:00:00', NULL, NULL, '2026-03-04 09:00:00');

INSERT INTO notifications (user_id, title, message, link, is_read, created_at) VALUES
(3, 'ມີຄຳຂໍລາລໍຖ້າອະນຸມັດ', 'ນັດຖະວຸດ ໃຈດີ ສົ່ງຄຳຂໍລາກິດ LV-2026-000002', '/approvals', 0, '2026-04-28 10:15:05'),
(4, 'ຄຳຂໍລາໄດ້ຮັບອະນຸມັດ', 'ຄຳຂໍລາ LV-2026-000001 ໄດ້ຮັບອະນຸມັດແລ້ວ', '/leave_requests/show.php?id=1', 0, '2026-04-20 09:30:05');

INSERT INTO audit_logs (
  user_id, action, entity_type, entity_id, old_value, new_value, ip_address, user_agent, created_at
) VALUES
(1, 'login', 'users', 1, NULL, JSON_OBJECT('email', 'admin@example.com'), '127.0.0.1', 'Seed Data', '2026-04-29 09:00:00'),
(4, 'create_leave_request', 'leave_requests', 2, NULL, JSON_OBJECT('request_no', 'LV-2026-000002', 'status', 'Pending'), '127.0.0.1', 'Seed Data', '2026-04-28 10:15:00'),
(3, 'approve_leave_request', 'leave_requests', 1, JSON_OBJECT('status', 'Pending'), JSON_OBJECT('status', 'Approved'), '127.0.0.1', 'Seed Data', '2026-04-20 09:30:00');

INSERT INTO settings (setting_key, setting_value, description) VALUES
('company_name', 'ບໍລິສັດ ຕົວຢ່າງ ຈຳກັດ', 'ຊື່ບໍລິສັດທີ່ສະແດງໃນລະບົບ'),
('timezone', 'Asia/Vientiane', 'ເຂດເວລາຫຼັກຂອງລະບົບ'),
('fiscal_year_start_month', '1', 'ເດືອນເລີ່ມຕົ້ນປີງົບປະມານ'),
('workdays', '1,2,3,4,5', 'ວັນເຮັດວຽກ 1=ຈັນ ເຖິງ 7=ອາທິດ'),
('exclude_weekends', '1', 'ບໍ່ນັບວັນເສົາອາທິດໃນການຄຳນວນວັນລາ'),
('max_upload_size_mb', '5', 'ຂະໜາດໄຟລ໌ແນບສູງສຸດເປັນ MB'),
('allowed_upload_extensions', 'pdf,jpg,jpeg,png', 'ນາມສະກຸນໄຟລ໌ແນບທີ່ອະນຸຍາດ'),
('login_background_image', '', 'ຮູບພື້ນຫຼັງໜ້າເຂົ້າສູ່ລະບົບ');

SET FOREIGN_KEY_CHECKS = 1;
