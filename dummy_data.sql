-- ============================================================
-- NARAK — Dummy Data for Presentation
-- ============================================================
-- Passwords follow the pattern: first_latin_letter + 12345678
--   سارة     → s12345678
--   محمد     → m12345678
--   فاطمة    → f12345678
--   عبدالله  → a12345678
--   لمى      → l12345678  (account is blocked)
-- ============================================================

SET NAMES utf8mb4;

-- ============================================================
-- 1. CUSTOMERS
-- ============================================================
INSERT INTO `customer`
  (`customer_id`, `first_name`, `last_name`, `email`, `phone_number`, `password_hash`, `address`, `status`)
VALUES
  (6,  'سارة',    'السالم',    'sara.alsalem@gmail.com',      '0501234561', '$2y$10$KXEJMz42sfH8mwYJV1SaLuj/eAXBrOhHmV3tcxa2YvGZCzgr1S60O', '', 'active'),
  (7,  'محمد',    'العمري',    'mohammed.omari@gmail.com',    '0501234562', '$2y$10$BPNPgqp0/ZFbRnnCEUUTxO9fLXkfOR7/d0DvWKE.CFpPTUtd5yWQq', '', 'active'),
  (8,  'فاطمة',   'الزهراني', 'fatima.zahrani@gmail.com',    '0501234563', '$2y$10$Xg3N02PI0xYuiYKweVwXd.WxGXhzbuLnY8FC81sS0VLSUznmykHqm', '', 'active'),
  (9,  'عبدالله', 'الحربي',   'abdullah.harbi@gmail.com',    '0501234564', '$2y$10$Dyq0fLpQDZ.OvntJi605QOKGcARcYDgdXR7pP0j7MFd15IScoNnca',  '', 'active'),
  (10, 'لمى',     'القحطاني', 'lama.qahtani@gmail.com',      '0501234565', '$2y$10$x0W0/mJ8iKOhuhfsoOiwtecVoSKnW1lgIe.9kcMXhQFqvAAQcBl1.',  '', 'blocked');

-- ============================================================
-- 2. TIME SLOTS
-- ============================================================
-- Past slots (completed appointments → is_available=0)
-- Past slots (cancelled appointments → is_available=1, freed)
-- Future slots (pending/confirmed → is_available=0)
-- Extra future available slots (for demo booking)
INSERT INTO `time_slot`
  (`slot_id`, `lab_id`, `slot_date`, `slot_time`, `is_available`)
VALUES
  -- past / completed
  (33, 2, '2026-03-15', '09:00:00', 0),
  (34, 1, '2026-03-25', '09:00:00', 0),
  (35, 3, '2026-04-10', '09:30:00', 0),
  (36, 4, '2026-04-05', '10:00:00', 0),
  (37, 4, '2026-04-10', '14:00:00', 0),

  -- past / cancelled (slot freed back)
  (38, 3, '2026-03-20', '11:00:00', 1),
  (39, 1, '2026-04-08', '11:00:00', 1),
  (40, 2, '2026-04-18', '15:00:00', 1),

  -- future / taken (pending or confirmed)
  (41, 1, '2026-05-10', '09:00:00', 0),
  (42, 2, '2026-05-12', '09:00:00', 0),
  (43, 3, '2026-05-15', '11:00:00', 0),
  (44, 1, '2026-05-20', '10:00:00', 0),
  (45, 2, '2026-05-22', '11:00:00', 0),
  (46, 2, '2026-05-25', '14:00:00', 0),

  -- future / available (for live booking demo)
  (47, 1, '2026-05-10', '10:00:00', 1),
  (48, 2, '2026-05-12', '10:00:00', 1),
  (49, 3, '2026-05-15', '12:00:00', 1),
  (50, 4, '2026-05-20', '11:00:00', 1),
  (51, 4, '2026-05-28', '09:00:00', 1),
  (52, 1, '2026-05-30', '14:00:00', 1);

-- ============================================================
-- 3. APPOINTMENTS
-- ============================================================
-- سارة (6):    completed + cancelled + pending
-- محمد (7):    completed × 2 + confirmed
-- فاطمة (8):   completed + cancelled + confirmed
-- عبدالله (9): completed + pending × 2
-- لمى (10):    cancelled + pending  (blocked user)
INSERT INTO `appointment`
  (`appointment_id`, `customer_id`, `lab_id`, `slot_id`, `status`, `created_at`)
VALUES
  -- سارة
  (8,  6, 2, 33, 'completed', '2026-03-13 10:00:00'),
  (9,  6, 3, 38, 'cancelled', '2026-03-18 09:00:00'),
  (10, 6, 1, 41, 'pending',   '2026-05-04 11:00:00'),

  -- محمد
  (11, 7, 1, 34, 'completed', '2026-03-23 08:30:00'),
  (12, 7, 3, 35, 'completed', '2026-04-08 10:00:00'),
  (13, 7, 2, 42, 'confirmed', '2026-05-03 14:00:00'),

  -- فاطمة
  (14, 8, 4, 36, 'completed', '2026-04-03 09:00:00'),
  (15, 8, 1, 39, 'cancelled', '2026-04-06 11:00:00'),
  (16, 8, 3, 43, 'confirmed', '2026-05-02 16:00:00'),

  -- عبدالله
  (17, 9, 4, 37, 'completed', '2026-04-08 08:00:00'),
  (18, 9, 1, 44, 'pending',   '2026-05-05 09:30:00'),
  (19, 9, 2, 45, 'pending',   '2026-05-05 09:45:00'),

  -- لمى (blocked)
  (20, 10, 2, 40, 'cancelled', '2026-04-16 13:00:00'),
  (21, 10, 2, 46, 'pending',   '2026-05-04 10:00:00');

-- ============================================================
-- 4. APPOINTMENT TEST TYPES
-- ============================================================
-- Lab 1 tests: فيتامين د(1)  ماغنيسيوم(2)  فيتامين ب12(3)
-- Lab 2 tests: فيتامين د(4)  ماغنيسيوم(5)  الحديد(6)
-- Lab 3 tests: فيتامين د(7)  ماغنيسيوم(8)  الكوليسترول الكلي(9)
-- Lab 4 tests: فيتامين د(10) ماغنيسيوم(11) هيموجلوبين(12)
INSERT INTO `appointment_test_type`
  (`appointment_id`, `test_type_id`)
VALUES
  -- appt 8  (sara, lab 2, completed)
  (8, 4), (8, 5),
  -- appt 9  (sara, lab 3, cancelled)
  (9, 7),
  -- appt 10 (sara, lab 1, pending)
  (10, 2),

  -- appt 11 (mohammed, lab 1, completed)
  (11, 1), (11, 3),
  -- appt 12 (mohammed, lab 3, completed)
  (12, 8), (12, 9),
  -- appt 13 (mohammed, lab 2, confirmed)
  (13, 5), (13, 6),

  -- appt 14 (fatima, lab 4, completed)
  (14, 10), (14, 11), (14, 12),
  -- appt 15 (fatima, lab 1, cancelled)
  (15, 1),
  -- appt 16 (fatima, lab 3, confirmed)
  (16, 9),

  -- appt 17 (abdullah, lab 4, completed)
  (17, 11), (17, 12),
  -- appt 18 (abdullah, lab 1, pending)
  (18, 1), (18, 2),
  -- appt 19 (abdullah, lab 2, pending)
  (19, 4),

  -- appt 20 (lama, lab 2, cancelled)
  (20, 5),
  -- appt 21 (lama, lab 2, pending)
  (21, 6);

-- ============================================================
-- 5. TEST RESULTS  (completed appointments only: 8,11,12,14,17)
-- ============================================================
INSERT INTO `test_result`
  (`result_id`, `appointment_id`, `test_type_id`, `result_value`, `normal_range`, `status_flag`, `report_date`)
VALUES
  -- appt 8 (sara, lab 2) ─ mixed
  (5,  8, 4,  '80',   '50 - 125',  'normal', '2026-03-15'),  -- فيتامين د  → طبيعي
  (6,  8, 5,  '160',  '200 - 900', 'low',    '2026-03-15'),  -- ماغنيسيوم  → منخفض

  -- appt 11 (mohammed, lab 1) ─ both abnormal
  (7,  11, 1,  '148',  '50 - 125',  'high',   '2026-03-25'),  -- فيتامين د  → مرتفع
  (8,  11, 3,  '7.2',  '8.6 - 10.2','low',    '2026-03-25'),  -- فيتامين ب12 → منخفض

  -- appt 12 (mohammed, lab 3) ─ mixed
  (9,  12, 8,  '520',  '200 - 900', 'normal', '2026-04-10'),  -- ماغنيسيوم       → طبيعي
  (10, 12, 9,  '215',  '120-200',   'high',   '2026-04-10'),  -- الكوليسترول الكلي → مرتفع

  -- appt 14 (fatima, lab 4) ─ mostly low
  (11, 14, 10, '35',   '50 - 125',  'low',    '2026-04-05'),  -- فيتامين د  → منخفض
  (12, 14, 11, '410',  '200 - 900', 'normal', '2026-04-05'),  -- ماغنيسيوم  → طبيعي
  (13, 14, 12, '10.5', '12-17.5',   'low',    '2026-04-05'),  -- هيموجلوبين → منخفض

  -- appt 17 (abdullah, lab 4) ─ mixed
  (14, 17, 11, '650',  '200 - 900', 'normal', '2026-04-10'),  -- ماغنيسيوم  → طبيعي
  (15, 17, 12, '18.5', '12-17.5',   'high',   '2026-04-10');  -- هيموجلوبين → مرتفع

-- ============================================================
-- 6. REPORTS  (labs reporting customers)
-- ============================================================
INSERT INTO `report`
  (`report_id`, `customer_id`, `lab_id`, `reason`, `report_date`, `status`)
VALUES
  (4, 7, 1, 'تأخر متكرر في الحضور للموعد دون إشعار مسبق',         '2026-03-28', 'open'),
  (5, 6, 3, 'سوء التعامل مع موظفي المختبر',                        '2026-04-20', 'open'),
  (6, 10, 2, 'تقديم معلومات مضللة عند الحجز - تم حظر الحساب',     '2026-04-19', 'closed');
