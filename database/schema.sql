-- NARAK database schema (structure only)
-- Derived from the team's local MySQL export. No customer records or credentials.
-- MySQL 8.0+, InnoDB, UTF-8
CREATE DATABASE IF NOT EXISTS narak_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE narak_db;

CREATE TABLE admin (
 admin_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(150) NOT NULL,
 email VARCHAR(191) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE customer (
 customer_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 first_name VARCHAR(100) NOT NULL,
 last_name VARCHAR(100) NOT NULL,
 email VARCHAR(191) NOT NULL UNIQUE,
 phone_number VARCHAR(20) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 address VARCHAR(500) DEFAULT NULL,
 status VARCHAR(50) DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE laboratory (
 lab_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 lab_name VARCHAR(150) NOT NULL,
 lab_logo VARCHAR(255) DEFAULT NULL,
 email VARCHAR(191) NOT NULL UNIQUE,
 phone_number VARCHAR(20) NOT NULL,
 address TEXT NOT NULL,
 password_hash VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE time_slot (
 slot_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 lab_id INT NOT NULL,
 slot_date DATE NOT NULL,
 slot_time TIME NOT NULL,
 is_available TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_lab_slot (lab_id,slot_date,slot_time),
 CONSTRAINT fk_time_slot_lab FOREIGN KEY (lab_id) REFERENCES laboratory(lab_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointment (
 appointment_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 customer_id INT NOT NULL,
 lab_id INT NOT NULL,
 slot_id INT NOT NULL,
 INDEX idx_appointment_slot (slot_id),
 admin_id INT DEFAULT NULL,
 status ENUM('pending','confirmed','completed','cancelled') NOT NULL DEFAULT 'pending',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_appointment_customer FOREIGN KEY (customer_id) REFERENCES customer(customer_id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_appointment_lab FOREIGN KEY (lab_id) REFERENCES laboratory(lab_id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_appointment_slot FOREIGN KEY (slot_id) REFERENCES time_slot(slot_id) ON UPDATE CASCADE,
 CONSTRAINT fk_appointment_admin FOREIGN KEY (admin_id) REFERENCES admin(admin_id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE test_type (
 test_type_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 lab_id INT NOT NULL,
 test_name VARCHAR(150) NOT NULL,
 price DECIMAL(10,2) NOT NULL,
 unit VARCHAR(50) DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 normal_range VARCHAR(255) DEFAULT NULL,
 CONSTRAINT fk_test_type_lab FOREIGN KEY (lab_id) REFERENCES laboratory(lab_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE appointment_test_type (
 appointment_id INT NOT NULL,
 test_type_id INT NOT NULL,
 PRIMARY KEY(appointment_id,test_type_id),
 CONSTRAINT fk_att_appointment FOREIGN KEY (appointment_id) REFERENCES appointment(appointment_id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_att_test_type FOREIGN KEY (test_type_id) REFERENCES test_type(test_type_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE test_result (
 result_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 appointment_id INT NOT NULL,
 test_type_id INT NOT NULL,
 result_value VARCHAR(100) NOT NULL,
 normal_range VARCHAR(100) DEFAULT NULL,
 status_flag ENUM('normal','low','high') NOT NULL,
 report_date DATE NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_test_result_appointment FOREIGN KEY (appointment_id) REFERENCES appointment(appointment_id) ON DELETE CASCADE ON UPDATE CASCADE,
 CONSTRAINT fk_test_result_test_type FOREIGN KEY (test_type_id) REFERENCES test_type(test_type_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE report (
 report_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
 customer_id INT NOT NULL,
 lab_id INT NOT NULL,
 reason VARCHAR(255) NOT NULL,
 report_date DATE NOT NULL,
 status VARCHAR(50) DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
