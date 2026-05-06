<script>
window.addEventListener('pageshow', function (e) {
  if (e.persisted) {
    window.location.replace('index.php');
  }
});
</script>
<?php if (!empty($_GET['welcome'])):
  $isSignup = $_GET['welcome'] === 'signup';
  $msg = $isSignup
    ? 'تم إنشاء حسابك بنجاح! مرحباً بك في نرعاك.'
    : 'تم تسجيل الدخول بنجاح! مرحباً بك.';
?>
<style>
#welcome-toast {
  position: fixed;
  top: 28px;
  left: 50%;
  transform: translateX(-50%) translateY(0);
  z-index: 9999;
  display: flex;
  align-items: center;
  gap: 14px;
  background: #fff;
  border-radius: 16px;
  box-shadow: 0 8px 32px rgba(0,0,0,0.13);
  padding: 18px 24px 18px 20px;
  min-width: 320px;
  max-width: 480px;
  border-right: 5px solid #2d7a3a;
  font-family: 'Tajawal', sans-serif;
  animation: toastIn 0.4s cubic-bezier(0.34,1.56,0.64,1) forwards;
  overflow: hidden;
}

#welcome-toast.hide {
  animation: toastOut 0.35s ease forwards;
}

@keyframes toastIn {
  from { opacity: 0; transform: translateX(-50%) translateY(-20px); }
  to   { opacity: 1; transform: translateX(-50%) translateY(0); }
}

@keyframes toastOut {
  from { opacity: 1; transform: translateX(-50%) translateY(0); }
  to   { opacity: 0; transform: translateX(-50%) translateY(-16px); }
}

.toast-icon {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: #e8f4ea;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
}

.toast-body {
  flex: 1;
}

.toast-title {
  font-size: 0.95rem;
  font-weight: 700;
  color: #1a1a1a;
  margin-bottom: 3px;
}

.toast-msg {
  font-size: 0.82rem;
  color: #666;
  line-height: 1.5;
}

.toast-close {
  background: none;
  border: none;
  font-size: 1rem;
  color: #bbb;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
  flex-shrink: 0;
  transition: color 0.2s;
}

.toast-close:hover { color: #888; }

.toast-progress {
  position: absolute;
  bottom: 0;
  right: 0;
  height: 3px;
  background: #2d7a3a;
  border-radius: 0 0 0 3px;
  animation: toastProgress 4s linear forwards;
  width: 100%;
}

@keyframes toastProgress {
  from { width: 100%; }
  to   { width: 0%; }
}
</style>

<div id="welcome-toast" role="alert">
  <div class="toast-icon">✓</div>
  <div class="toast-body">
    <div class="toast-title"><?= $isSignup ? 'تم التسجيل بنجاح' : 'تم تسجيل الدخول' ?></div>
    <div class="toast-msg"><?= htmlspecialchars($msg) ?></div>
  </div>
  <button class="toast-close" onclick="dismissToast()">✕</button>
  <div class="toast-progress"></div>
</div>

<script>
(function () {
  history.replaceState(null, '', window.location.pathname);
  var t = setTimeout(dismissToast, 4000);
  function dismissToast() {
    clearTimeout(t);
    var el = document.getElementById('welcome-toast');
    if (!el) return;
    el.classList.add('hide');
    el.addEventListener('animationend', function () { el.remove(); });
  }
  window.dismissToast = dismissToast;
})();
</script>
<?php endif; ?>
