<?php
session_start();

if (isset($_SESSION['role'])) {
    if ($_SESSION['role'] === 'customer') {
        header("Location: customer-dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'lab') {
        header("Location: lab-dashboard.php");
        exit;
    } elseif ($_SESSION['role'] === 'admin') {
        header("Location: admin-dashboard.php");
        exit;
    }
}
?>
<?php
$active_panel = $_GET['panel'] ?? 'login';
$signup_form  = $_SESSION['signup_form'] ?? [];
unset($_SESSION['signup_form']);
$error_msg = '';
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'missing_fields':   $error_msg = 'الرجاء تعبئة جميع الحقول'; break;
        case 'missing_name':     $error_msg = 'الرجاء إدخال الاسم الأول واسم العائلة'; break;
        case 'missing_email':    $error_msg = 'الرجاء إدخال البريد الإلكتروني'; break;
        case 'missing_phone':    $error_msg = 'الرجاء إدخال رقم الجوال'; break;
        case 'missing_password': $error_msg = 'الرجاء إدخال كلمة المرور'; break;
        case 'invalid_email':    $error_msg = 'البريد الإلكتروني غير صحيح'; break;
        case 'email_exists':     $error_msg = 'هذا البريد الإلكتروني مسجل مسبقًا'; break;
        case 'phone_exists':     $error_msg = 'رقم الجوال مستخدم مسبقًا'; break;
        case 'invalid_name':     $error_msg = 'الاسم يجب أن يكون باللغة العربية فقط'; break;
        case 'invalid_phone':    $error_msg = 'رقم الجوال يجب أن يكون 10 أرقام فقط'; break;
        case 'password_too_short': $error_msg = 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'; break;
        case 'invalid_address':  $error_msg = 'رابط الموقع غير صحيح'; break;
        case 'signup_failed':    $error_msg = 'حدث خطأ أثناء إنشاء الحساب، حاول مرة أخرى'; break;
        case 'empty_login':      $error_msg = 'الرجاء تعبئة جميع الحقول'; break;
        case 'wrong_password':   $error_msg = 'كلمة المرور غير صحيحة'; break;
        case 'user_not_found':   $error_msg = 'البريد الإلكتروني غير مسجل'; break;
        case 'invalid_role':     $error_msg = 'نوع الحساب غير صحيح'; break;
    }
}
?>
    
    
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>نرعاك - تسجيل الدخول</title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@300;400;500;700;800&display=swap" rel="stylesheet">
<style>
  :root {
    --deep-red: #520000;
    --muted-brown: #BD9E77;
    --light-beige: #ECC590;
    --medium-brown: #8E775E;
    --ivory: #FFFFF0;
    --black: #000000;
  }

  * { margin: 0; padding: 0; box-sizing: border-box; }

  body {
    font-family: 'Tajawal', sans-serif;
    background: var(--ivory);
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .page-wrapper {
    width: 100vw;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    padding: 24px 0;
  }

  /* Decorative background */
  .bg-decor {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    pointer-events: none;
    z-index: 0;
  }
  .bg-decor::before {
    content: '';
    position: absolute;
    top: -120px; left: -120px;
    width: 400px; height: 400px;
    background: radial-gradient(circle, rgba(82,0,0,0.08) 0%, transparent 70%);
    border-radius: 50%;
  }
  .bg-decor::after {
    content: '';
    position: absolute;
    bottom: -100px; right: -100px;
    width: 350px; height: 350px;
    background: radial-gradient(circle, rgba(189,158,119,0.12) 0%, transparent 70%);
    border-radius: 50%;
  }

  .card {
    background: #fff;
    border-radius: 24px;
    box-shadow: 0 20px 80px rgba(82,0,0,0.12), 0 4px 20px rgba(0,0,0,0.06);
    width: 900px;
    max-width: 96vw;
    min-height: 560px;
    display: flex;
    position: relative;
    z-index: 1;
    animation: slideUp 0.6s cubic-bezier(0.16,1,0.3,1) both;
  }

  @keyframes slideUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
  }

  /* Left panel (decorative side) */
  .side-panel {
    width: 320px;
    min-height: 100%;
    background: var(--deep-red);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 48px 32px;
    position: relative;
    overflow: hidden;
    flex-shrink: 0;
  }

  .side-panel::before {
    content: '';
    position: absolute;
    top: -60px; right: -60px;
    width: 200px; height: 200px;
    background: rgba(255,255,255,0.04);
    border-radius: 50%;
  }
  .side-panel::after {
    content: '';
    position: absolute;
    bottom: -80px; left: -80px;
    width: 250px; height: 250px;
    background: rgba(189,158,119,0.08);
    border-radius: 50%;
  }

  .side-panel .logo-area {
    text-align: center;
    position: relative;
    z-index: 2;
  }

.logo-img {
  width: 250px;
  height: 250px;
  object-fit: contain;
}

  .side-panel h1 {
    font-size: 2.6rem;
    font-weight: 800;
    color: #fff;
    letter-spacing: -1px;
    margin-bottom: 6px;
  }

  .side-panel .tagline {
    font-size: 0.9rem;
    color: rgba(255,255,255,0.55);
    font-weight: 400;
    line-height: 1.6;
    text-align: center;
    margin-top: 12px;
  }

  .side-tabs {
    margin-top: 48px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
    position: relative;
    z-index: 2;
  }

  .side-tab {
    padding: 14px 20px;
    border-radius: 12px;
    font-family: 'Tajawal', sans-serif;
    font-size: 0.95rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.25s ease;
    text-align: center;
    border: none;
    text-decoration: none;
    display: block;
  }

  .side-tab.active {
    background: #fff;
    color: var(--deep-red);
  }

  .side-tab.inactive {
    background: transparent;
    color: rgba(255,255,255,0.65);
    border: 1px solid rgba(255,255,255,0.2);
  }

  .side-tab.inactive:hover {
    background: rgba(255,255,255,0.08);
    color: #fff;
  }

  /* Main form area */
  .form-area {
    flex: 1;
    padding: 52px 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
  }

  .form-header {
    margin-bottom: 36px;
  }

  .form-header h2 {
    font-size: 1.8rem;
    font-weight: 800;
    color: var(--deep-red);
    margin-bottom: 6px;
  }

  .form-header p {
    font-size: 0.9rem;
    color: var(--medium-brown);
    font-weight: 400;
  }

  /* Panels */
  .panel { display: none; }
  .panel.active { display: block; }

  .form-group {
    margin-bottom: 20px;
  }

  label {
    display: block;
    font-size: 0.85rem;
    font-weight: 600;
    color: #444;
    margin-bottom: 8px;
  }

  input[type="text"],
  input[type="email"],
  input[type="password"],
  input[type="tel"],
  input[type="url"],
  select {
    width: 100%;
    padding: 13px 16px;
    border: 1.5px solid #e8e0d8;
    border-radius: 10px;
    font-family: 'Tajawal', sans-serif;
    font-size: 0.95rem;
    color: #333;
    background: #faf8f5;
    transition: border-color 0.2s, box-shadow 0.2s;
    outline: none;
    text-align: right;
  }

  input:focus, select:focus {
    border-color: var(--deep-red);
    box-shadow: 0 0 0 3px rgba(82,0,0,0.07);
    background: #fff;
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
  }

  .btn-primary {
    width: 100%;
    padding: 14px;
    background: var(--deep-red);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-family: 'Tajawal', sans-serif;
    font-size: 1rem;
    font-weight: 700;
    cursor: pointer;
    margin-top: 8px;
    transition: background 0.2s, transform 0.15s;
    letter-spacing: 0.3px;
  }

  .btn-primary:hover {
    background: #3d0000;
    transform: translateY(-1px);
  }

  .forgot {
    font-size: 0.82rem;
    color: var(--muted-brown);
    text-decoration: none;
    display: block;
    text-align: left;
    margin-top: -10px;
    margin-bottom: 16px;
  }

  .forgot:hover { color: var(--deep-red); }

  .divider {
    text-align: center;
    font-size: 0.8rem;
    color: #aaa;
    margin: 20px 0;
    position: relative;
  }
  .divider::before, .divider::after {
    content: '';
    position: absolute;
    top: 50%;
    width: 40%;
    height: 1px;
    background: #e8e0d8;
  }
  .divider::before { right: 0; }
  .divider::after { left: 0; }

  .role-selector {
    display: flex;
    gap: 10px;
    margin-bottom: 22px;
  }

  .role-btn {
    flex: 1;
    padding: 10px 8px;
    border: 1.5px solid #e8e0d8;
    border-radius: 10px;
    background: #faf8f5;
    font-family: 'Tajawal', sans-serif;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.2s;
    color: #666;
    text-align: center;
  }

  .role-btn.selected {
    border-color: var(--deep-red);
    background: rgba(82,0,0,0.05);
    color: var(--deep-red);
  }

  .role-label {
    font-size: 0.82rem;
    font-weight: 600;
    color: #555;
    margin-bottom: 10px;
  }

  /* Dashboard links at bottom */
  .demo-links {
    position: fixed;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    display: flex;
    gap: 12px;
    z-index: 100;
    background: rgba(255,255,255,0.92);
    padding: 10px 18px;
    border-radius: 50px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.1);
    backdrop-filter: blur(10px);
  }

  .demo-links span {
    font-size: 0.75rem;
    color: #888;
    display: flex;
    align-items: center;
  }

  .demo-link {
    font-size: 0.78rem;
    font-weight: 600;
    color: var(--deep-red);
    text-decoration: none;
    padding: 5px 12px;
    border-radius: 20px;
    border: 1px solid rgba(82,0,0,0.2);
    transition: all 0.2s;
  }

  .demo-link:hover {
    background: var(--deep-red);
    color: #fff;
  }

  .field-hint {
    display: block;
    font-size: 0.78rem;
    color: #c0392b;
    margin-top: 5px;
    font-weight: 600;
  }

  .optional-tag {
    font-size: 0.75rem;
    font-weight: 400;
    color: #aaa;
  }

  .error-msg {
    background: #fff0f0;
    border: 1px solid #f5c6c6;
    color: #c0392b;
    border-radius: 8px;
    padding: 10px 14px;
    font-size: 0.88rem;
    font-weight: 600;
    margin-bottom: 16px;
    text-align: right;
  }
</style>
</head>
<body>

<div class="bg-decor"></div>

<div class="page-wrapper">
  <div class="card">

    <!-- Side Panel -->
    <div class="side-panel">
      <div class="logo-area">
        <div class="logo-icon">
        <img src="images/2.png" alt="نرعاك" class="logo-img">
        </div>
        <p class="tagline">منصة خدمات المختبرات الصحية<br>في مدينة الرياض</p>
      </div>

      <div class="side-tabs">
        <button class="side-tab active" onclick="showPanel('login', this)">تسجيل الدخول</button>
        <button class="side-tab inactive" onclick="showPanel('signup', this)">إنشاء حساب جديد</button>
      </div>
    </div>

    <!-- Form Area -->
    <div class="form-area">
<!-- LOGIN PANEL -->
<form action="login_process.php" method="POST" novalidate>
  <div class="panel active" id="panel-login">
    <div class="form-header">
      <h2>أهلاً بعودتك</h2>
      <p>سجّل دخولك للوصول إلى خدمات نرعاك</p>
    </div>

    <div class="role-label">نوع الحساب</div>
    <div class="role-selector">
      <label class="role-btn selected">
        <input type="radio" name="role" value="customer" checked hidden>
        عميل
      </label>

      <label class="role-btn">
        <input type="radio" name="role" value="lab" hidden>
        مختبر
      </label>

      <label class="role-btn">
        <input type="radio" name="role" value="admin" hidden>
        المدير
      </label>
    </div>

    <div class="form-group">
      <label>البريد الإلكتروني</label>
      <input type="email" name="email" placeholder="example@email.com" required 
        oninvalid="this.setCustomValidity('الرجاء إدخال بريد إلكتروني صحيح')"
        oninput="this.setCustomValidity('')">
    </div>

    <div class="form-group">
      <label>كلمة المرور</label>
      <input type="password" name="password" placeholder="••••••••" required
        oninvalid="this.setCustomValidity('الرجاء إدخال كلمة المرور')"
        oninput="this.setCustomValidity('')">
    </div>

<?php if ($active_panel === 'login' && $error_msg): ?>
      <div class="error-msg"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <button class="btn-primary" type="submit">تسجيل الدخول</button>
  </div>
</form>
      <!-- SIGNUP PANEL -->
      <form action="signup_process.php" method="POST" novalidate>
      <div class="panel" id="panel-signup">
        <div class="form-header">
          <h2>إنشاء حساب جديد</h2>
          <p>انضم إلى منصة نرعاك لخدمات المختبرات</p>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label>الاسم الأول</label>
            <input type="text" name="first_name" placeholder="الاسم الأول" required
                pattern="[؀-ۿ\s]+"
                value="<?= htmlspecialchars($signup_form['first_name'] ?? '') ?>"
                oninvalid="this.setCustomValidity('الاسم يجب أن يكون باللغة العربية فقط')"
                oninput="this.setCustomValidity('')">
          </div>
          <div class="form-group">
            <label>اسم العائلة</label>
            <input type="text" name="last_name" placeholder="اسم العائلة" required
                pattern="[؀-ۿ\s]+"
                value="<?= htmlspecialchars($signup_form['last_name'] ?? '') ?>"
                oninvalid="this.setCustomValidity('الاسم يجب أن يكون باللغة العربية فقط')"
                oninput="this.setCustomValidity('')">
          </div>
        </div>

        <div class="form-group">
          <label>البريد الإلكتروني</label>
          <input type="email" name="email" placeholder="example@email.com" required
            value="<?= htmlspecialchars($signup_form['email'] ?? '') ?>"
            oninvalid="this.setCustomValidity('الرجاء إدخال بريد إلكتروني صحيح')"
            oninput="this.setCustomValidity('')">
        </div>

        <div class="form-group">
          <label>رقم الجوال</label>
          <input type="tel" name="phone" placeholder="05xxxxxxxx" required
            pattern="[0-9]{10}"
            value="<?= htmlspecialchars($signup_form['phone'] ?? '') ?>"
            oninvalid="this.setCustomValidity('رقم الجوال يجب أن يكون 10 أرقام فقط')"
            oninput="this.setCustomValidity('')">
        </div>

        <div class="form-group">
          <label>كلمة المرور</label>
          <input type="password" name="password"
            placeholder="<?= ($active_panel === 'signup' && $error_msg) ? 'أعد إدخال كلمة المرور' : '••••••••' ?>"
            required minlength="8"
            oninvalid="this.setCustomValidity('كلمة المرور يجب أن تكون 8 أحرف على الأقل')"
            oninput="this.setCustomValidity('')">
          <?php if ($active_panel === 'signup' && $error_msg): ?>
            <span class="field-hint">يرجى إعادة إدخال كلمة المرور</span>
          <?php endif; ?>
        </div>

        <div class="form-group">
          <label>موقع المنزل <span class="optional-tag">(اختياري)</span></label>
          <input type="url" name="address" placeholder="https://maps.google.com/..."
            value="<?= htmlspecialchars($signup_form['address'] ?? '') ?>">
        </div>

        <?php if ($active_panel === 'signup' && $error_msg): ?>
          <div class="error-msg"><?= htmlspecialchars($error_msg) ?></div>
        <?php endif; ?>
        <div class="error-msg" id="signup-js-error" style="display:none;">الرجاء تعبئة جميع الحقول المطلوبة</div>

        <button class="btn-primary" type="submit">إنشاء الحساب</button>
      </div>
    </form>
      
    </div>
  </div>
</div>
<script>
function showPanel(name, btn) {
  document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
  document.getElementById('panel-' + name).classList.add('active');

  document.querySelectorAll('.side-tab').forEach(t => {
    t.classList.remove('active');
    t.classList.add('inactive');
  });

  btn.classList.add('active');
  btn.classList.remove('inactive');
}

document.addEventListener('DOMContentLoaded', function () {
  var panel = <?= json_encode($active_panel) ?>;
  if (panel !== 'login') {
    var tabs = document.querySelectorAll('.side-tab');
    // tabs[0] = login, tabs[1] = signup
    var idx = panel === 'signup' ? 1 : 0;
    showPanel(panel, tabs[idx]);
  }
});
</script>
<script>
document.querySelector('form[action="signup_process.php"]').addEventListener('submit', function (e) {
  var fields = ['first_name', 'last_name', 'email', 'phone', 'password'];
  var allEmpty = fields.every(function (name) {
    return !document.querySelector('[name="' + name + '"]').value.trim();
  });
  var errEl = document.getElementById('signup-js-error');
  if (allEmpty) {
    e.preventDefault();
    errEl.style.display = 'block';
    errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  } else {
    errEl.style.display = 'none';
  }
});

document.querySelectorAll('.role-selector .role-btn').forEach(label => {
  label.addEventListener('click', function () {
    document.querySelectorAll('.role-selector .role-btn').forEach(btn => btn.classList.remove('selected'));
    this.classList.add('selected');

    const radio = this.querySelector('input[type="radio"]');
    if (radio) {
      radio.checked = true;
    }
  });
});
</script>
</body>
</html>