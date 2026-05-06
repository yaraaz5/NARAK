<style>
#uiModalOverlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.45);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  font-family: 'Tajawal', sans-serif;
  direction: rtl;
}
#uiModalOverlay.open { display: flex; }

#uiModalBox {
  background: #fff;
  border-radius: 18px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.2);
  padding: 36px 32px 28px;
  width: 360px;
  max-width: 92vw;
  text-align: center;
  animation: uiModalIn 0.22s cubic-bezier(0.34,1.56,0.64,1) both;
}
@keyframes uiModalIn {
  from { opacity: 0; transform: scale(0.88) translateY(12px); }
  to   { opacity: 1; transform: scale(1) translateY(0); }
}

#uiModalIcon {
  width: 56px; height: 56px;
  border-radius: 50%;
  margin: 0 auto 18px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.6rem;
}
#uiModalIcon.confirm { background: #fff3e0; }
#uiModalIcon.alert   { background: #fce8e8; }

#uiModalMsg {
  font-size: 1rem;
  font-weight: 600;
  color: #222;
  line-height: 1.6;
  margin-bottom: 28px;
}

#uiModalActions { display: flex; gap: 12px; justify-content: center; }

.ui-modal-btn {
  flex: 1;
  padding: 11px 0;
  border-radius: 10px;
  font-family: 'Tajawal', sans-serif;
  font-size: 0.95rem;
  font-weight: 700;
  cursor: pointer;
  border: none;
  transition: background 0.18s, transform 0.12s;
  max-width: 140px;
}
.ui-modal-btn:hover { transform: translateY(-1px); }
.ui-modal-btn.ok     { background: #520000; color: #fff; }
.ui-modal-btn.ok:hover { background: #3d0000; }
.ui-modal-btn.cancel { background: #f0ece8; color: #555; }
.ui-modal-btn.cancel:hover { background: #e4ddd7; }
</style>

<div id="uiModalOverlay">
  <div id="uiModalBox">
    <div id="uiModalIcon"></div>
    <div id="uiModalMsg"></div>
    <div id="uiModalActions">
      <button class="ui-modal-btn cancel" id="uiModalCancelBtn" onclick="_uiModalCancel()">إلغاء</button>
      <button class="ui-modal-btn ok"     id="uiModalOkBtn"     onclick="_uiModalOk()">موافق</button>
    </div>
  </div>
</div>

<script>
(function () {
  var _onOk = null;

  window.showConfirm = function (msg, onConfirm) {
    _onOk = onConfirm;
    document.getElementById('uiModalMsg').textContent  = msg;
    document.getElementById('uiModalIcon').className   = 'confirm';
    document.getElementById('uiModalIcon').textContent = '⚠️';
    document.getElementById('uiModalCancelBtn').style.display = '';
    document.getElementById('uiModalOverlay').classList.add('open');
  };

  window.showAlert = function (msg) {
    _onOk = null;
    document.getElementById('uiModalMsg').textContent  = msg;
    document.getElementById('uiModalIcon').className   = 'alert';
    document.getElementById('uiModalIcon').textContent = '!';
    document.getElementById('uiModalCancelBtn').style.display = 'none';
    document.getElementById('uiModalOverlay').classList.add('open');
  };

  window._uiModalOk = function () {
    document.getElementById('uiModalOverlay').classList.remove('open');
    if (_onOk) { var cb = _onOk; _onOk = null; cb(); }
  };

  window._uiModalCancel = function () {
    _onOk = null;
    document.getElementById('uiModalOverlay').classList.remove('open');
  };

  document.getElementById('uiModalOverlay').addEventListener('click', function (e) {
    if (e.target === this) window._uiModalCancel();
  });
})();
</script>
