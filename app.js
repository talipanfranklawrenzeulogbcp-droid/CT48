/* Great Solomon Manpower Services Inc. Core Transaction 4 - single combined JavaScript file */
document.addEventListener('DOMContentLoaded', () => {
  if(window.FEEDBACK_SENT){ setTimeout(showFeedbackSentModal, 100); }
  const toggle=document.getElementById('sidebarToggle');
  const sidebar=document.getElementById('sidebar');
  const backdrop=document.getElementById('sidebar-backdrop');
  if(toggle&&sidebar){toggle.addEventListener('click',()=>{sidebar.classList.toggle('open');backdrop&&backdrop.classList.toggle('show');});}
  if(backdrop){backdrop.addEventListener('click',()=>{sidebar?.classList.remove('open');backdrop.classList.remove('show');});}
  document.addEventListener('keydown',e=>{if(e.key==='Escape'){document.getElementById('modalRoot')?.replaceChildren();sidebar?.classList.remove('open');backdrop?.classList.remove('show');}});
  document.querySelectorAll('input[type="number"]').forEach(i=>i.addEventListener('wheel',e=>e.preventDefault(),{passive:false}));
  refreshNotificationIndicator();
  window.setInterval(refreshNotificationIndicator,15000);
  document.querySelectorAll('form').forEach(form=>form.addEventListener('submit',()=>{const btn=form.querySelector('button[type="submit"],button:not([type])');if(btn){btn.disabled=true;btn.dataset.originalText=btn.innerHTML;btn.innerHTML='<span class="material-symbols-outlined">hourglass_top</span> Processing...';setTimeout(()=>{btn.disabled=false;btn.innerHTML=btn.dataset.originalText||'Submit';},4000);}}));
});
function showModal(title,body){const root=document.getElementById('modalRoot');if(!root)return;root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal"><div class="gw-modal-head"><strong>${escapeHtml(title)}</strong><button class="gw-modal-close" onclick="closeModal()"><span class="material-symbols-outlined">close</span></button></div><div class="gw-modal-body"><p style="font-size:12px;line-height:1.7;color:#64748b">${escapeHtml(body)}</p><div style="display:flex;justify-content:flex-end;margin-top:20px"><button class="gw-btn primary" onclick="closeModal()">Continue</button></div></div></div></div>`;}
function closeModal(){document.getElementById('modalRoot')?.replaceChildren();}
function escapeHtml(v){return String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}

function refreshNotificationIndicator(){
 fetch(`${window.APP_BASE||''}/includes/notification_poll.php`,{credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
   .then(r=>r.ok?r.json():null).then(data=>{
     if(!data||!data.ok)return;
     if(Array.isArray(data.notifications)){
       if(window.CURRENT_USER?.role==='Administrator') window.ADMIN_NOTIFICATIONS=data.notifications;
       else if(window.CURRENT_USER?.role==='Staff') window.STAFF_NOTIFICATIONS=data.notifications;
     }
     const button=document.querySelector('.gw-user-button'); if(!button)return;
     let dot=button.querySelector('.notification-red-dot');
     if(Number(data.unread)>0){
       if(!dot){dot=document.createElement('span');dot.className='notification-red-dot';dot.setAttribute('aria-label','New notification');button.prepend(dot);}
     } else if(dot){dot.remove();}
     const menuButton=document.querySelector('#userMenu button[onclick="showNotificationModal()"]');
     if(menuButton){
       let badge=menuButton.querySelector('.notification-badge');
       if(Number(data.unread)>0){
         if(!badge){badge=document.createElement('span');badge.className='notification-badge';menuButton.appendChild(badge);}
         badge.textContent=String(data.unread);
       } else if(badge){badge.remove();}
     }
   }).catch(()=>{});
}
function toggleUserMenu(){
 const m=document.getElementById('userMenu'); if(!m)return;
 m.classList.toggle('open');
}
document.addEventListener('click',e=>{
 const wrap=document.querySelector('.user-menu-wrap');
 if(wrap && !wrap.contains(e.target)) document.getElementById('userMenu')?.classList.remove('open');
});

function notificationAction(id,action){
 const body=new URLSearchParams({id:String(id),action:String(action),csrf_token:String(window.CSRF_TOKEN||'')});
 return fetch(`${window.APP_BASE||''}/includes/notification_action.php`,{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest','Content-Type':'application/x-www-form-urlencoded'},body,credentials:'same-origin'})
   .then(async response=>{
     let data=null;
     try{data=await response.json();}catch(e){}
     if(!response.ok || !data?.ok) throw new Error(data?.error||'Unable to update the notification.');
     return data;
   });
}
function notificationFilterData(notes,filter,isStaff){
 if(filter==='unread') return notes.filter(n=>Number(n.is_read)===0);
 if(filter==='replied' && !isStaff) return notes.filter(n=>n.type==='feedback' && Number(n.has_reply)===1);
 return notes;
}
function showNotificationModal(filter='all'){
 document.getElementById('userMenu')?.classList.remove('open');
 const root=document.getElementById('modalRoot'); if(!root)return;
 const isStaff=window.CURRENT_USER?.role==='Staff';
 const notes=isStaff?(Array.isArray(window.STAFF_NOTIFICATIONS)?window.STAFF_NOTIFICATIONS:[]):(Array.isArray(window.ADMIN_NOTIFICATIONS)?window.ADMIN_NOTIFICATIONS:[]);
 const activeFilter=['all','unread','replied'].includes(filter)?filter:'all';
 const filtered=notificationFilterData(notes,activeFilter,isStaff);
 const title=isStaff?'Notifications':'Admin Notifications';
 const subtitle=isStaff?'Replies to your feedback and system notifications.':'Staff feedback and system notifications.';
 const empty=activeFilter==='unread'?'No unread notifications.':activeFilter==='replied'?'No replied feedback yet.':isStaff?'No notifications yet':'No staff feedback yet';

 const body=filtered.length ? filtered.map(n=>{
   const unread=Number(n.is_read)===0;
   const date=new Date(String(n.created_at||'').replace(' ','T'));
   const when=isNaN(date.getTime())?String(n.created_at||''):date.toLocaleString();
   const isFeedback=n.type==='feedback';
   const isReply=n.type==='feedback_reply';
   const view=`<button type="button" class="gw-btn secondary" data-notification-view data-id="${Number(n.id)||0}" data-title="${escapeHtml(n.title||'Notification')}" data-sender-name="${escapeHtml(n.sender_name||'System')}" data-sender-role="${escapeHtml(n.sender_role||'System')}" data-message="${escapeHtml(n.message||'')}" data-type="${escapeHtml(n.type||'system')}" data-was-unread="${unread?'1':'0'}" data-is-staff="${isStaff?'1':'0'}"><span class="material-symbols-outlined">visibility</span> View</button>`;
   const reply=(window.CURRENT_USER?.role==='Administrator' && isFeedback) ? `<button type="button" class="gw-btn secondary" data-notification-reply data-id="${Number(n.id)||0}" data-sender-name="${escapeHtml(n.sender_name||'Staff')}" data-sender-role="${escapeHtml(n.sender_role||'Staff')}" data-message="${escapeHtml(n.message||'')}"><span class="material-symbols-outlined">reply</span> Reply</button>` : '';
   const del=(window.CURRENT_USER?.role==='Administrator' && isFeedback) ? `<button type="button" class="notification-delete" title="Remove feedback" aria-label="Remove feedback" data-notification-delete data-id="${Number(n.id)||0}"><span class="material-symbols-outlined">close</span></button>` : '';
   const status=isReply?'Admin reply':(isFeedback && Number(n.has_reply)===1?'Replied':(unread?'Unread':'Seen'));
   return `<div class="notification-card ${unread?'unread':''}">
     <div class="notification-card-head"><div><strong>${escapeHtml(n.title||'Notification')}</strong><span>${escapeHtml(n.sender_name||'System')} · ${escapeHtml(n.sender_role||'System')} · ${escapeHtml(status)}</span></div><small>${escapeHtml(when)}</small>${del}</div>
     <p>${escapeHtml(n.message||'')}</p><div class="notification-actions">${view}${reply}</div>
   </div>`;
 }).join('') : `<div class="notification-empty"><span class="material-symbols-outlined">notifications_none</span><strong>${empty}</strong><p>${escapeHtml(subtitle)}</p></div>`;
 const filters=!isStaff ? `<div class="notification-filters"><button type="button" class="gw-btn ${activeFilter==='all'?'primary':'secondary'}" data-notification-filter="all">All</button><button type="button" class="gw-btn ${activeFilter==='unread'?'primary':'secondary'}" data-notification-filter="unread">Unread</button><button type="button" class="gw-btn ${activeFilter==='replied'?'primary':'secondary'}" data-notification-filter="replied">Replied</button></div>` : `<div class="notification-filters"><button type="button" class="gw-btn ${activeFilter==='all'?'primary':'secondary'}" data-notification-filter="all">All</button><button type="button" class="gw-btn ${activeFilter==='unread'?'primary':'secondary'}" data-notification-filter="unread">Unread</button></div>`;
 root.innerHTML=`<div class="gw-modal-backdrop" data-close-modal-backdrop><div class="gw-modal notifications-modal">
   <div class="gw-modal-head"><div><strong>${title}</strong><small>${subtitle}</small></div><button type="button" class="gw-modal-close" data-close-modal aria-label="Close">×</button></div>
   <div class="gw-modal-body">${filters}<div class="notification-list">${body}</div><div class="record-actions"><button type="button" class="gw-btn primary" data-close-modal>Close</button></div></div>
 </div></div>`;
 root.querySelector('[data-close-modal-backdrop]')?.addEventListener('click',event=>{if(event.target===event.currentTarget)closeModal();});
 root.querySelectorAll('[data-close-modal]').forEach(button=>button.addEventListener('click',closeModal));
 root.querySelectorAll('[data-notification-filter]').forEach(button=>button.addEventListener('click',()=>showNotificationModal(button.dataset.notificationFilter||'all')));
 root.querySelectorAll('[data-notification-view]').forEach(button=>button.addEventListener('click',()=>viewNotification(
   Number(button.dataset.id), button.dataset.title||'Notification', button.dataset.senderName||'System', button.dataset.senderRole||'System', button.dataset.message||'', button.dataset.wasUnread==='1', button.dataset.isStaff==='1', button.dataset.type||'system'
 )));
 root.querySelectorAll('[data-notification-reply]').forEach(button=>button.addEventListener('click',()=>showFeedbackReply(
   Number(button.dataset.id), button.dataset.senderName||'Staff', button.dataset.senderRole||'Staff', button.dataset.message||''
 )));
 root.querySelectorAll('[data-notification-delete]').forEach(button=>button.addEventListener('click',()=>removeNotification(Number(button.dataset.id))));
}
function viewNotification(id,title,senderName,senderRole,message,wasUnread,isStaff,type='system'){
 const notes=isStaff?(Array.isArray(window.STAFF_NOTIFICATIONS)?window.STAFF_NOTIFICATIONS:[]):(Array.isArray(window.ADMIN_NOTIFICATIONS)?window.ADMIN_NOTIFICATIONS:[]);
 const item=notes.find(n=>Number(n.id)===Number(id));
 const detailTitle=isStaff?(type==='feedback_reply'?'Administrator Reply':'Notification Details'):(type==='feedback'?'Staff Feedback':'Notification Details');
 const detailSubtitle=isStaff?(type==='feedback_reply'?'Reply details':'System notification details'):(type==='feedback'?'Staff feedback details':'System notification details');
 const messageLabel=isStaff?(type==='feedback_reply'?'Administrator Reply':'Notification Message'):(type==='feedback'?'Staff Feedback':'Notification Message');
 const show=()=>{
   const root=document.getElementById('modalRoot'); if(!root)return;
   if(item) item.is_read=1;
   root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal notification-view-modal">
    <div class="gw-modal-head"><div><strong>${escapeHtml(detailTitle)}</strong><small>${escapeHtml(detailSubtitle)}</small></div><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
    <div class="gw-modal-body">
      <div class="notification-detail-grid">
        <div class="notification-detail-field"><span>Name of Sender</span><strong>${escapeHtml(senderName||'Unknown')}</strong></div>
        <div class="notification-detail-field"><span>Role</span><strong>${escapeHtml(senderRole||'Unknown')}</strong></div>
      </div>
      <div class="notification-detail-field notification-feedback-field"><span>${escapeHtml(messageLabel)}</span><div class="notification-full-message">${escapeHtml(message||'No feedback content was provided.')}</div></div>
      <div class="record-actions"><button class="gw-btn primary" onclick="closeModal()">Close</button></div>
    </div>
   </div></div>`;
   if(item) item.is_read=1;
   refreshNotificationIndicator();
 };
 if(!wasUnread){ show(); return; }
 notificationAction(id,'read').then(show).catch(()=>{
   showModal('Unable to mark feedback as read','The feedback could not be marked as read. Please check the database connection and try again.');
 });
}

function removeNotification(id){
 if(!confirm('Remove this staff feedback from the admin notifications?')) return;
 notificationAction(id,'delete').then(()=>{
   if(Array.isArray(window.ADMIN_NOTIFICATIONS)) window.ADMIN_NOTIFICATIONS=window.ADMIN_NOTIFICATIONS.filter(n=>Number(n.id)!==Number(id));
   refreshNotificationIndicator();
   showNotificationModal('all');
 }).catch(e=>showModal('Unable to remove feedback',e.message||'Please try again.'));
}
function showFeedbackReply(notificationId,staffName,staffRole,originalMessage){
 closeModal();
 showFeedbackModal({replyTo:Number(notificationId),recipientName:staffName,recipientRole:staffRole,originalMessage:originalMessage});
}
function showFeedbackSentModal(){
 const root=document.getElementById('modalRoot'); if(!root)return;
 root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal feedback-sent-modal">
   <div class="feedback-sent-icon"><span class="material-symbols-outlined">mark_email_read</span></div>
   <div class="gw-modal-body feedback-sent-body"><strong>Success</strong><p>Your message has been sent successfully.</p><button class="gw-btn primary" onclick="closeModal()">Done</button></div>
 </div></div>`;
}

function appRelativeReturnPath(){
 const base=String(window.APP_BASE||'').replace(/\/+$/,'');
 let path=String(window.location.pathname||'/dashboard.php');
 if(base && (path===base || path.startsWith(base+'/'))) path=path.slice(base.length)||'/';
 if(!path.startsWith('/')) path='/'+path;
 return path+String(window.location.search||'');
}

function showFeedbackModal(replyContext=null){
 document.getElementById('userMenu')?.classList.remove('open');
 // Feedback submission is employee-only; administrators can respond through Notifications.
 if(!replyContext && window.CURRENT_USER?.role!=='Staff'){
   if(window.CURRENT_USER?.role==='Administrator') showNotificationModal();
   return;
 }
 if(replyContext && window.CURRENT_USER?.role!=='Administrator') return;
 const root=document.getElementById('modalRoot'); if(!root)return;
 const user=window.CURRENT_USER||{name:'User',role:'Staff'};
 const replying=!!replyContext;
 const recipientName=replyContext?.recipientName||'';
 const recipientRole=replyContext?.recipientRole||'';
 const original=replyContext?.originalMessage||'';
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal feedback-modal">
 <div class="gw-modal-head"><div><strong>${replying?'Reply to Feedback':'Send Feedback'}</strong><small>${replying?'Send a response directly to '+escapeHtml(recipientName)+'.':'Help us improve the Great Solomon Manpower Services system.'}</small></div><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
 <div class="gw-modal-body">
 ${replying?`<div class="feedback-reply-context"><strong>Original feedback from ${escapeHtml(recipientName)} (${escapeHtml(recipientRole)})</strong><p>${escapeHtml(original)}</p></div>`:`<div class="feedback-intro"><span class="material-symbols-outlined">rate_review</span><div><strong>Your account details are automatic</strong><p>Your name and role are taken from the account currently signed in.</p></div></div>`}
 <form method="post" action="${window.APP_BASE||''}/includes/feedback.php">
 <input type="hidden" name="csrf_token" value="${escapeHtml(window.CSRF_TOKEN||'')}"><input type="hidden" name="return_to" value="${escapeHtml(appRelativeReturnPath())}">
 ${replying?`<input type="hidden" name="action" value="reply"><input type="hidden" name="notification_id" value="${Number(replyContext.replyTo)}">`:''}
 <div class="feedback-account-grid"><div class="feedback-readonly-field"><label>${replying?'From':'Name'}</label><div class="feedback-readonly-value"><span class="material-symbols-outlined">person</span>${escapeHtml(user.name)}</div></div><div class="feedback-readonly-field"><label>${replying?'To':'Role'}</label><div class="feedback-readonly-value"><span class="material-symbols-outlined">${replying?'person':'badge'}</span>${escapeHtml(replying?recipientName:user.role)}</div></div></div>
 ${!replying?`<div class="form-grid feedback-classification"><div><label for="feedbackCategory">Category</label><select id="feedbackCategory" name="category" required><option>General Suggestion</option><option>System Bug</option><option>Feature Request</option><option>Workplace Concern</option><option>Security Concern</option><option>Other</option></select></div><div><label for="feedbackPriority">Priority</label><select id="feedbackPriority" name="priority" required><option>Low</option><option selected>Medium</option><option>High</option><option>Critical</option></select></div></div>`:''}
 <div class="feedback-message-field"><label for="feedbackText">${replying?'Reply':'Your Feedback'}</label><textarea id="feedbackText" name="feedback" rows="7" required maxlength="3000" placeholder="${replying?'Write your reply...':'Tell us what worked well, what should be improved, or if you found a problem...'}"></textarea><div class="feedback-helper"><span>Please avoid including passwords or other sensitive information.</span><span id="feedbackCharCount">0 / 3,000</span></div></div>
 <div class="record-actions"><button type="button" class="gw-btn secondary" onclick="closeModal()">Cancel</button><button class="gw-btn primary" type="submit"><span class="material-symbols-outlined">${replying?'reply':'send'}</span>${replying?'Send Reply':'Send Feedback'}</button></div>
 </form></div></div></div>`;
 const feedbackInput=document.getElementById('feedbackText');
 const counter=document.getElementById('feedbackCharCount');
 const updateCount=()=>{if(counter&&feedbackInput){const count=Array.from(feedbackInput.value).length;counter.textContent=`${count.toLocaleString()} / 3,000`;counter.style.color=count>3000?'#b91c1c':'';}};
 feedbackInput?.addEventListener('input',updateCount); updateCount();
 setTimeout(()=>feedbackInput?.focus(),50);
}
function showTermsModal(){
 document.getElementById('userMenu')?.classList.remove('open');
 const root=document.getElementById('modalRoot'); if(!root)return;
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal terms-modal">
 <div class="gw-modal-head"><div><strong>Terms and Conditions</strong><small>Great Solomon Manpower Services Inc. — Core Transaction 4</small></div><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
 <div class="gw-modal-body terms-body">
 <p>By accessing and using this system, you acknowledge that it is intended only for authorized Great Solomon Manpower Services Inc. administrators and staff. You are responsible for using your assigned account appropriately, keeping your password and verification information confidential, and ensuring that records you create or update are accurate and used only for legitimate company purposes. Sharing accounts, attempting to access another user's account, bypassing access controls, or using the system for unauthorized purposes is prohibited.</p>
 <p>The system processes personal and, where applicable, sensitive personal information. The company will handle such information in accordance with the <strong>Data Privacy Act of 2012 (Republic Act No. 10173)</strong>, including its principles on transparency, legitimate purpose, proportionality, and appropriate protection of personal information. Users must not disclose, copy, download, or otherwise process personal information beyond what is authorized for their work responsibilities.</p>
 <p>Users must also use the system and its computer resources responsibly and must not perform unauthorized access, interception, alteration, deletion, disruption, introduction of malicious code, or other prohibited activity. The <strong>Cybercrime Prevention Act of 2012 (Republic Act No. 10175)</strong> addresses offenses involving the confidentiality, integrity, and availability of computer data and systems, and this system's security controls and audit records may be used to support legitimate security and compliance activities.</p>
 <p>For workplace health and safety records, users must enter and maintain information responsibly and support the company's safety processes. The <strong>Occupational Safety and Health Standards Law (Republic Act No. 11058)</strong> strengthens compliance with occupational safety and health standards and provides duties and protections relating to workplace hazards, safety programs, training, incident reporting, and worker safety. Records in this system should therefore be used only for authorized health, safety, welfare, and compliance purposes.</p>
 <p>Electronic records, messages, and transactions handled through this system may also be subject to the <strong>Electronic Commerce Act of 2000 (Republic Act No. 8792)</strong> and other applicable Philippine laws and regulations. By continuing to use the system, you agree to follow company policies, applicable laws, and authorized instructions; system activity may be logged for security, audit, operational, and compliance purposes. These terms describe system-use rules and are not a substitute for legal advice; applicable laws and regulations prevail where they conflict with these terms.</p>
 <div class="terms-note"><span class="material-symbols-outlined">verified_user</span><span>Use the system responsibly and report security, privacy, or data-quality concerns to the appropriate administrator.</span></div>
 <div class="record-actions"><button type="button" class="gw-btn primary" onclick="closeModal()">I Understand</button></div>
 </div></div></div>`;
}
function showEditUserModal(id,name,email,role){
 const root=document.getElementById('modalRoot'); if(!root)return;
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal">
 <div class="gw-modal-head"><strong>Edit User Account</strong><button class="gw-modal-close" onclick="closeModal()">×</button></div>
 <div class="gw-modal-body"><form method="post" action="${window.location.pathname}">
 <input type="hidden" name="csrf_token" value="${escapeHtml(window.CSRF_TOKEN||'')}"><input type="hidden" name="action" value="edit_user"><input type="hidden" name="id" value="${escapeHtml(id)}">
 <div class="form-grid edit-user-fields">
 <div class="full"><label>New Name</label><input class="edit-user-input" name="name" value="${escapeHtml(name)}" required autocomplete="name"></div>
 <div class="full"><label>New Email</label><input class="edit-user-input" type="email" name="email" value="${escapeHtml(email)}" required autocomplete="email"></div>
 <div class="full"><label>New Role</label><select class="edit-user-input" name="role" required><option value="Administrator" ${role==="Administrator"?"selected":""}>Administrator</option><option value="Staff" ${role==="Staff"?"selected":""}>Staff</option></select></div>
 <div class="full"><label>New Password</label><input class="edit-user-input" type="password" name="password" minlength="6" placeholder="Leave blank to keep current password" autocomplete="new-password"></div></div>
 <div class="record-actions"><button type="button" class="gw-btn secondary" onclick="closeModal()">Cancel</button><button class="gw-btn primary">Save Changes</button></div>
 </form></div></div></div>`;
}

document.addEventListener('click',event=>{
 const button=event.target.closest('[data-edit-user]');
 if(!button)return;
 showEditUserModal(button.dataset.id||'',button.dataset.name||'',button.dataset.email||'',button.dataset.role||'Staff');
});

/* CT4 Gemini AI Assistant */
document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('aiForm'), input=document.getElementById('aiInput'), messages=document.getElementById('aiMessages');
 if(!form||!input||!messages)return;
 const clearBtn=document.getElementById('aiClear');
 const base=window.APP_BASE||'';
 let history=[];
 try{history=JSON.parse(sessionStorage.getItem('ct4_ai_history')||'[]');}catch(e){history=[];}
 function esc(v){return String(v).replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));}
 function renderText(v){return esc(v).replace(/\n/g,'<br>');}
 function addMessage(role,text){
   const row=document.createElement('div'); row.className='ai-message '+(role==='user'?'user':'assistant');
   row.innerHTML=role==='user'
    ? '<div class="ai-message-content"><strong>You</strong><p>'+renderText(text)+'</p></div>'
    : '<div class="ai-msg-icon"><span class="material-symbols-outlined">auto_awesome</span></div><div><strong>CT4 AI</strong><p>'+renderText(text)+'</p></div>';
   messages.appendChild(row); messages.scrollTop=messages.scrollHeight;
 }
 function save(){try{sessionStorage.setItem('ct4_ai_history',JSON.stringify(history.slice(-8)));}catch(e){/* Chat remains usable if browser storage is disabled. */}}
 function setBusy(b){input.disabled=b;form.querySelector('button[type="submit"]').disabled=b;}
 function ask(text){
   text=(text||'').trim(); if(!text||input.disabled)return;
   addMessage('user',text);
   history.push({role:'user',text:text});
   save(); input.value=''; input.style.height='auto'; setBusy(true);
   const thinking=document.createElement('div'); thinking.className='ai-message assistant ai-thinking'; thinking.innerHTML='<div class="ai-msg-icon"><span class="material-symbols-outlined">auto_awesome</span></div><div><strong>CT4 AI</strong><p><span class="ai-dots">Thinking…</span></p></div>'; messages.appendChild(thinking); messages.scrollTop=messages.scrollHeight;
   const fd=new FormData(); fd.append('message',text); fd.append('history',JSON.stringify(history.slice(0,-1).slice(-8))); fd.append('csrf_token',window.CSRF_TOKEN||''); // The endpoint appends the current question separately.
   fetch(base+'/services/api/gemini.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json().catch(()=>({ok:false,error:r.status===503||r.status===504?'The AI service is temporarily unavailable at the server gateway. Check the Gemini API key and server error log.':'The server returned an unreadable response.'})).then(data=>({status:r.status,data})))
    .then(({status,data})=>{
      thinking.remove();
      if(data.ok){
        addMessage('assistant',data.answer); history.push({role:'model',text:data.answer}); save();
      }else{
        const detail=data.error||'Please try again.';
        addMessage('assistant','I could not answer that right now. '+detail+(status>=500?' (HTTP '+status+')':''));
      }
    }).catch(()=>{thinking.remove();addMessage('assistant','The AI service could not be reached. Check the server connection and Gemini configuration, then try again.');})
    .finally(()=>setBusy(false));
 }
 form.addEventListener('submit',e=>{e.preventDefault();ask(input.value);});
 input.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();ask(input.value);}});
 input.addEventListener('input',()=>{input.style.height='auto';input.style.height=Math.min(input.scrollHeight,150)+'px';});
 document.querySelectorAll('.ai-suggestions button').forEach(b=>b.addEventListener('click',()=>ask(b.dataset.prompt||'')));
 clearBtn?.addEventListener('click',()=>{history=[];try{sessionStorage.removeItem('ct4_ai_history');}catch(e){} messages.innerHTML='<div class="ai-message assistant"><div class="ai-msg-icon"><span class="material-symbols-outlined">auto_awesome</span></div><div><strong>CT4 AI</strong><p>Chat cleared. What would you like to know about Core Transaction 4?</p></div></div>';});
 document.getElementById('aiExport')?.addEventListener('click',()=>{
   const transcript=history.map(item=>(item.role==='user'?'You':'CT4 AI')+':\\n'+String(item.text||'')).join('\\n\\n');
   if(!transcript.trim()){addMessage('assistant','There is no conversation to export yet.');return;}
   const blob=new Blob([transcript+'\\n'],{type:'text/plain;charset=utf-8'});
   const url=URL.createObjectURL(blob); const link=document.createElement('a');
   link.href=url; link.download='ct4-ai-conversation.txt'; document.body.appendChild(link); link.click(); link.remove(); URL.revokeObjectURL(url);
 });
});

/* CT4 global account tools: theme, data storage, logout confirmation, inactivity timer */
function formatBytes(bytes){
  bytes=Number(bytes)||0;
  if(bytes<1024) return bytes+' B';
  if(bytes<1024*1024) return (bytes/1024).toFixed(1)+' KB';
  if(bytes<1024*1024*1024) return (bytes/1024/1024).toFixed(1)+' MB';
  return (bytes/1024/1024/1024).toFixed(1)+' GB';
}
window.DATA_STORAGE_HAS_FILES=null;
function showDataStorageModal(){
  document.getElementById('userMenu')?.classList.remove('open');
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop data-storage-backdrop" onclick="if(event.target===this)closeModal()">
    <div class="gw-modal data-storage-modal">
      <div class="gw-modal-head"><div><strong>Data Storage</strong><small>Data/files received from other branches.</small></div><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
      <div class="gw-modal-body">
        <div class="data-storage-toolbar">
          <div><strong>DATA / FILES</strong><span>Stored data/files received from other branches.</span></div>
          <form id="dataStorageUploadForm" class="data-storage-upload" onsubmit="handleDataStorageUpload(event)">
            <input type="file" name="data_file" id="dataStorageFileInput" required>
            <input type="text" name="source_branch" placeholder="Branch / Source (optional)">
            <button class="gw-btn primary" type="submit" id="dataStorageUploadBtn"><span class="material-symbols-outlined">upload</span>UPLOAD</button>
            <button class="gw-btn secondary" type="button" onclick="showDownloadAllConfirm()"><span class="material-symbols-outlined">download</span>DOWNLOAD ALL</button>
          </form>
        </div>
        <div id="dataStorageList" class="data-storage-list"><div class="data-storage-loading"><span class="material-symbols-outlined">progress_activity</span>Loading stored files...</div></div>
      </div>
    </div>
  </div>`;
  loadDataStorageList();
}
function handleDataStorageUpload(e){
  e.preventDefault();
  const form=e.target;
  const fileInput=form.querySelector('input[type="file"]');
  if(!fileInput || !fileInput.files.length) return;
  const btn=document.getElementById('dataStorageUploadBtn');
  const originalHtml=btn?btn.innerHTML:'';
  if(btn){ btn.disabled=true; btn.innerHTML='<span class="material-symbols-outlined">hourglass_top</span> Uploading...'; }
  const fd=new FormData(form);
  fd.append('csrf_token',String(window.CSRF_TOKEN||''));
  fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=upload`,{
    method:'POST',
    body:fd,
    credentials:'same-origin',
    headers:{'X-Requested-With':'XMLHttpRequest'}
  })
    .then(r=>r.json().catch(()=>({ok:false,error:'Server returned invalid response.'})))
    .then(data=>{
      if(btn){ btn.disabled=false; btn.innerHTML=originalHtml; }
      if(data.ok){
        window.DATA_STORAGE_HAS_FILES=null;
        form.reset();
        loadDataStorageList();
        showModal('Success','File uploaded and stored successfully.');
      } else {
        showModal('Upload Failed',data.error||'Unable to upload file.');
      }
    })
    .catch(err=>{
      if(btn){ btn.disabled=false; btn.innerHTML=originalHtml; }
      showModal('Upload Error',err.message||'Failed to communicate with server.');
    });
}
function loadDataStorageList(){
  fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=list`,{credentials:'same-origin'})
    .then(r=>r.json()).then(data=>{
      const box=document.getElementById('dataStorageList'); if(!box)return;
      window.DATA_STORAGE_HAS_FILES=!!(data.ok && Array.isArray(data.items) && data.items.length);
      if(!window.DATA_STORAGE_HAS_FILES){
        box.innerHTML=`<div class="notification-empty"><span class="material-symbols-outlined">folder_off</span><strong>NO DATA/FILES STORED YET.</strong><p>DATA/FILES RECEIVED FROM OTHER BRANCHES WILL APPEAR HERE.</p></div>`; return;
      }
      box.innerHTML=data.items.map(f=>`<button type="button" class="data-storage-item" onclick="showStoredFile(${Number(f.id)},${escapeHtml(JSON.stringify(String(f.file_name)))},${escapeHtml(JSON.stringify(String(f.file_type||'')))})">
        <span class="data-storage-file-icon material-symbols-outlined">${String(f.file_type||'').startsWith('image/')?'image':'description'}</span>
        <span class="data-storage-file-main"><strong>${escapeHtml(f.file_name)}</strong><small>${escapeHtml(f.source_branch||'Other Branch')} · ${escapeHtml(formatBytes(f.file_size))}</small></span>
        <span class="material-symbols-outlined">chevron_right</span>
      </button>`).join('');
    }).catch(()=>{window.DATA_STORAGE_HAS_FILES=null;const box=document.getElementById('dataStorageList');if(box)box.innerHTML='<div class="notification-empty"><span class="material-symbols-outlined">error</span><strong>UNABLE TO LOAD DATA/FILES.</strong><p>PLEASE TRY AGAIN.</p></div>';});
}
function showStoredFile(id,name,type){
  const root=document.getElementById('modalRoot'); if(!root)return;
  const src=`${window.APP_BASE||''}/includes/data_storage.php?action=view&id=${encodeURIComponent(id)}`;
  const normalizedType=String(type||'').toLowerCase();
  const isImg = ['image/jpeg','image/png','image/gif','image/webp'].includes(normalizedType) && /\.(jpe?g|png|gif|webp)$/i.test(name);
  const isPdf = normalizedType === 'application/pdf' && /\.pdf$/i.test(name);
  const isTxt = ['text/plain','text/csv'].includes(normalizedType) && /\.(txt|csv|log)$/i.test(name);
  let previewContent = '';
  if (isImg) {
    previewContent = `<div style="display:flex;align-items:center;justify-content:center;height:100%;background:#f1f5f9;padding:12px"><img src="${src}" alt="${escapeHtml(name)}" style="max-width:100%;max-height:100%;object-fit:contain;border-radius:8px"></div>`;
  } else if (isPdf || isTxt) {
    previewContent = `<iframe src="${src}" title="${escapeHtml(name)}" style="width:100%;height:100%;border:0"></iframe>`;
  } else {
    previewContent = `<div style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;color:#64748b;text-align:center;padding:24px"><span class="material-symbols-outlined" style="font-size:54px;color:#4f46e5">description</span><strong>${escapeHtml(name)}</strong><p style="margin:0;font-size:13px">Direct preview is not available for this file type (${escapeHtml(type||'binary')}).<br>You can safely download the file below to view it.</p></div>`;
  }
  root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)showDataStorageModal()"><div class="gw-modal data-file-viewer-modal">
    <div class="gw-modal-head"><div><strong>${escapeHtml(name)}</strong><small>${escapeHtml(type||'Stored file')}</small></div><button class="gw-modal-close" onclick="showDataStorageModal()" aria-label="Back">×</button></div>
    <div class="gw-modal-body">
      <div class="data-file-preview">${previewContent}</div>
      <div class="record-actions"><button type="button" class="gw-btn secondary" onclick="showDownloadConfirm(${Number(id)},${escapeHtml(JSON.stringify(String(name)))})"><span class="material-symbols-outlined">download</span>DOWNLOAD</button><button type="button" class="gw-btn btn-danger" onclick="deleteStoredFile(${Number(id)},${escapeHtml(JSON.stringify(String(name)))})"><span class="material-symbols-outlined">delete</span>DELETE</button></div>
    </div>
  </div></div>`;
}
function deleteStoredFile(id,name){
  const fd=new FormData(); fd.append('id',id); fd.append('csrf_token',String(window.CSRF_TOKEN||''));
  if(!confirm('Delete '+name+'? The file will be moved to Archive and can be recovered later.')) return;
  fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=delete`,{method:'POST',body:fd,credentials:'same-origin'})
   .then(r=>r.json()).then(data=>{if(!data.ok)throw new Error(data.error||'Delete failed.');window.DATA_STORAGE_HAS_FILES=null;showDataStorageModal();})
   .catch(e=>showModal('Delete failed',e.message));
}
function showDownloadConfirm(id,name){
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><div><strong>DOWNLOAD CONFIRMATION</strong></div><button class="gw-modal-close" onclick="showStoredFile(${Number(id)},${escapeHtml(JSON.stringify(String(name)))},'Stored file')">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">download</span></div><p class="confirmation-text">ARE YOU SURE TO DOWNLOAD THE DATA/FILES</p>
      <div class="record-actions"><button class="gw-btn secondary" type="button" onclick="showStoredFile(${Number(id)},${escapeHtml(JSON.stringify(String(name)))},'Stored file')">NO</button><button class="gw-btn primary" type="button" onclick="window.location.href='${window.APP_BASE||''}/includes/data_storage.php?action=download&id=${Number(id)}'">YES, DOWNLOAD</button></div>
    </div>
  </div></div>`;
}
function showNoDownloadableFilesModal(){
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><strong>DOWNLOAD ALL</strong><button class="gw-modal-close" onclick="showDataStorageModal()" aria-label="Close">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">folder_off</span></div>
      <p class="confirmation-text">THERE ARE NO DATA/FILES CAN BE DOWNLOAD</p>
      <div class="record-actions"><button class="gw-btn primary" type="button" onclick="showDataStorageModal()">OK</button></div>
    </div>
  </div></div>`;
}
function showDownloadAllConfirm(){
  if(window.DATA_STORAGE_HAS_FILES===false){ showNoDownloadableFilesModal(); return; }
  if(window.DATA_STORAGE_HAS_FILES===null){
    fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=list`,{credentials:'same-origin'})
      .then(r=>r.json()).then(data=>{
        window.DATA_STORAGE_HAS_FILES=!!(data.ok && Array.isArray(data.items) && data.items.length);
        if(window.DATA_STORAGE_HAS_FILES) showDownloadAllConfirm();
        else showNoDownloadableFilesModal();
      }).catch(()=>showNoDownloadableFilesModal());
    return;
  }
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><strong>DOWNLOAD ALL CONFIRMATION</strong><button class="gw-modal-close" onclick="showDataStorageModal()" aria-label="Close">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">download_for_offline</span></div><p class="confirmation-text">ARE YOU SURE TO DOWNLOAD ALL DATA/FILES</p>
      <div class="record-actions"><button class="gw-btn secondary" type="button" onclick="showDataStorageModal()">NO</button><button class="gw-btn primary" type="button" onclick="window.location.href='${window.APP_BASE||''}/includes/data_storage.php?action=download_all'">YES, DOWNLOAD ALL</button></div>
    </div>
  </div></div>`;
}
function showLogoutModal(){
  document.getElementById('userMenu')?.classList.remove('open');
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop"><div class="gw-modal confirmation-modal">
    <div class="gw-modal-head"><strong>LOGOUT CONFIRMATION</strong><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
    <div class="gw-modal-body"><div class="confirmation-icon"><span class="material-symbols-outlined">logout</span></div><p class="confirmation-text">ARE YOU SURE YOU WANT TO LOGOUT</p>
      <div class="record-actions"><button class="gw-btn secondary" type="button" onclick="closeModal()">NO</button><button class="gw-btn primary" type="button" onclick="window.location.href='${window.APP_BASE||''}/auth/logout.php'">YES</button></div>
    </div>
  </div></div>`;
}

/* Automatic logout: only authenticated users, exactly 5 minutes of inactivity.
   Login and OTP pages do not expose CURRENT_USER and are therefore excluded. */
(function(){
  const LIMIT=5*60*1000;
  let lastActivity=Date.now(), timerId=null, lastMove=0;
  function logout(){ if(timerId)clearTimeout(timerId); window.location.href=`${window.APP_BASE||''}/auth/logout.php?reason=inactivity`; }
  function schedule(){ clearTimeout(timerId); timerId=setTimeout(logout,LIMIT); }
  function markActivity(){
    lastActivity=Date.now();
    schedule();
  }
  function init(){
    if(!window.CURRENT_USER || !window.CURRENT_USER.name) return;
    ['keydown','mousedown','touchstart','scroll','click','wheel','input','change','focus'].forEach(evt=>window.addEventListener(evt,markActivity,{passive:true}));
    window.addEventListener('mousemove',()=>{
      const now=Date.now(); if(now-lastMove>300){lastMove=now;markActivity();}
    },{passive:true});
    markActivity();
    setInterval(()=>{ if(Date.now()-lastActivity>=LIMIT) logout(); },10000);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
})();

/* Generate module shortcuts from the module's actual content sections.
   Existing links are reused; duplicate shortcuts are never created. */
(function(){
  function slug(text){return String(text).toLowerCase().trim().replace(/&/g,'and').replace(/[^a-z0-9]+/g,'-').replace(/^-+|-+$/g,'').slice(0,70);}
  function initShortcuts(){
    const path=window.location.pathname||'';
    const modulePaths=['/modules/health_safety/','/modules/legal_compliance/','/modules/system_admin_security/','/modules/asset_equipment/'];
    if(!modulePaths.some(p=>path.includes(p))) return;
    const shell=document.querySelector('.page-shell'); if(!shell)return;
    const existingBar=shell.querySelector('.gw-quick-actions');
    const bar=existingBar||document.createElement('section');
    bar.className='gw-quick-actions module-auto-shortcuts';
    const used=new Set([...bar.querySelectorAll('a[href^="#"]')].map(a=>a.getAttribute('href')));
    const sections=[...shell.querySelectorAll(':scope > section')].filter(sec=>{
      const h=sec.querySelector('.gw-panel-head h2, h2');
      return h && !sec.classList.contains('gw-hero') && !sec.classList.contains('gw-stats') && !sec.classList.contains('gw-quick-actions');
    });
    sections.forEach(sec=>{
      const h=sec.querySelector('.gw-panel-head h2, h2'); if(!h)return;
      if(!sec.id) sec.id=slug(h.textContent);
      if(!sec.id)return;
      const href='#'+sec.id; if(used.has(href))return;
      used.add(href);
      const a=document.createElement('a'); a.href=href;
      a.innerHTML='<span class="material-symbols-outlined">shortcut</span>'+h.textContent.trim();
      bar.appendChild(a);
    });
    if(!existingBar && bar.children.length){ const hero=shell.querySelector('.gw-hero'); hero?.after(bar); }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initShortcuts);else initShortcuts();
})();

/* Archive: deleted records/files are retained and can be recovered into data storage/database. */
function showArchiveModal(){
  document.getElementById('userMenu')?.classList.remove('open');
  const root=document.getElementById('modalRoot'); if(!root)return;
  root.innerHTML=`<div class="gw-modal-backdrop archive-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal archive-modal">
    <div class="gw-modal-head"><div><strong>Archive</strong><small>Deleted data and files retained for recovery.</small></div><button class="gw-modal-close" onclick="closeModal()">×</button></div>
    <div class="gw-modal-body"><div id="archiveList" class="data-storage-list"><div class="data-storage-loading"><span class="material-symbols-outlined">progress_activity</span>Loading archive...</div></div></div>
  </div></div>`;
  fetch(`${window.APP_BASE||''}/includes/archive.php?action=list`,{credentials:'same-origin'})
   .then(r=>r.json()).then(data=>{
    const box=document.getElementById('archiveList'); if(!box)return;
    if(!data.ok||!data.items?.length){box.innerHTML='<div class="notification-empty"><span class="material-symbols-outlined">inventory_2</span><strong>Archive is empty</strong><p>Deleted data and files will appear here.</p></div>';return;}
    box.innerHTML=data.items.map(x=>`<div class="data-storage-item archive-item">
      <span class="data-storage-file-icon material-symbols-outlined">${x.item_type==='file'?'description':'dataset'}</span>
      <span class="data-storage-file-main"><strong>${escapeHtml(x.item_name)}</strong><small>${escapeHtml(x.item_type)} · Deleted ${escapeHtml(x.deleted_at||'')}</small></span>
      <div style="display:flex;gap:6px;align-items:center">
        <button class="gw-btn primary" type="button" onclick="recoverArchive(${Number(x.id)})"><span class="material-symbols-outlined">restore</span>Recover</button>
        <button class="gw-btn btn-danger" type="button" onclick="deleteArchiveItem(${Number(x.id)},${escapeHtml(JSON.stringify(String(x.item_name)))})"><span class="material-symbols-outlined">delete_forever</span>Delete</button>
      </div>
    </div>`).join('');
   }).catch(()=>{const box=document.getElementById('archiveList');if(box)box.innerHTML='<div class="notification-empty"><strong>Unable to load archive.</strong><p>Please try again.</p></div>';});
}
function recoverArchive(id){
  const fd=new FormData(); fd.append('id',id); fd.append('action','recover'); fd.append('csrf_token',String(window.CSRF_TOKEN||''));
  fetch(`${window.APP_BASE||''}/includes/archive.php?action=recover`,{method:'POST',body:fd,credentials:'same-origin'})
   .then(r=>r.json()).then(data=>{if(!data.ok)throw new Error(data.error||'Recovery failed.');window.DATA_STORAGE_HAS_FILES=null;showArchiveModal();})
   .catch(e=>showModal('Recovery failed',e.message));
}
function deleteArchiveItem(id,name){
  if(!confirm('Permanently delete "'+name+'"? This action cannot be undone.')) return;
  const fd=new FormData(); fd.append('id',id); fd.append('action','delete'); fd.append('csrf_token',String(window.CSRF_TOKEN||''));
  fetch(`${window.APP_BASE||''}/includes/archive.php?action=delete`,{method:'POST',body:fd,credentials:'same-origin'})
   .then(r=>r.json()).then(data=>{if(!data.ok)throw new Error(data.error||'Delete failed.');showArchiveModal();})
   .catch(e=>showModal('Delete failed',e.message));
}

/* Module top navigation: enabled only on the four module pages. */
(function(){
  function initModuleTop(){
    const btn=document.getElementById('moduleTopButton');
    if(!btn)return;
    const toggle=()=>btn.classList.toggle('visible',window.scrollY>280);
    btn.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));
    window.addEventListener('scroll',toggle,{passive:true});
    toggle();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initModuleTop);else initModuleTop();
})();

/* Release-file picker: reuses Data Storage without exposing its management controls. */
function getSelectedReleaseFiles(){
 const box=document.getElementById('releaseStorageFileIds');
 if(!box)return [];
 return [...box.querySelectorAll('input[name="storage_file_ids[]"]')].map(i=>Number(i.value)).filter(Boolean);
}
function renderSelectedReleaseFiles(){
 const display=document.getElementById('selectedReleaseFile'); if(!display)return;
 const items=window.SELECTED_RELEASE_FILES||[];
 display.innerHTML=items.length ? items.map(f=>`<div class="selected-file-chip"><span class="material-symbols-outlined">description</span><span class="selected-file-chip-name" title="${escapeHtml(f.name)}">${escapeHtml(f.name)}</span><button type="button" onclick="removeReleaseFile(${Number(f.id)})" aria-label="Remove ${escapeHtml(f.name)}"><span class="material-symbols-outlined">close</span></button></div>`).join('') : '<div class="selected-file-note">No data/file selected.</div>';
 const hidden=document.getElementById('releaseStorageFileIds');
 if(hidden)hidden.innerHTML=items.map(f=>`<input type="hidden" name="storage_file_ids[]" value="${Number(f.id)}">`).join('');
}
function showReleaseFileStoragePicker(){
 const root=document.getElementById('modalRoot'); if(!root)return;
 const selected=new Set((window.SELECTED_RELEASE_FILES||[]).map(f=>Number(f.id)));
 root.innerHTML=`<div class="gw-modal-backdrop" onclick="if(event.target===this)closeModal()"><div class="gw-modal data-storage-modal">
   <div class="gw-modal-head"><div><strong>Data Storage</strong><small>Choose one or more data/files to release.</small></div><button class="gw-modal-close" onclick="closeModal()" aria-label="Close">×</button></div>
   <div class="gw-modal-body"><div id="releaseStoragePickerList" class="data-storage-list"><div class="data-storage-loading"><span class="material-symbols-outlined">progress_activity</span>Loading stored files...</div></div><div class="record-actions"><button type="button" class="gw-btn primary" onclick="closeModal()">Done</button></div></div>
 </div></div>`;
 fetch(`${window.APP_BASE||''}/includes/data_storage.php?action=list`,{credentials:'same-origin'}).then(r=>r.json()).then(data=>{
   const box=document.getElementById('releaseStoragePickerList'); if(!box)return;
   if(!data.ok || !Array.isArray(data.items) || !data.items.length){box.innerHTML='<div class="notification-empty"><span class="material-symbols-outlined">folder_off</span><strong>NO DATA/FILES STORED YET.</strong></div>';return;}
   box.innerHTML=data.items.map(f=>{
     const checked=selected.has(Number(f.id));
     return `<button type="button" class="data-storage-item ${checked?'selected-storage-item':''}" onclick="toggleReleaseFileSelection(${Number(f.id)},${escapeHtml(JSON.stringify(String(f.file_name)))},${escapeHtml(JSON.stringify(String(f.file_type||'')))})"><span class="data-storage-file-icon material-symbols-outlined">${checked?'check_circle':'description'}</span><span class="data-storage-file-main"><strong>${escapeHtml(f.file_name)}</strong><small>${escapeHtml(f.source_branch||'Other Branch')} · ${escapeHtml(formatBytes(f.file_size))}</small></span><span class="material-symbols-outlined">${checked?'check':'add'}</span></button>`;
   }).join('');
 }).catch(()=>{const box=document.getElementById('releaseStoragePickerList');if(box)box.innerHTML='<div class="notification-empty"><span class="material-symbols-outlined">error</span><strong>UNABLE TO LOAD DATA/FILES.</strong><p>Please try again.</p></div>';});
}
function toggleReleaseFileSelection(id,name,type){
 window.SELECTED_RELEASE_FILES=window.SELECTED_RELEASE_FILES||[];
 const i=window.SELECTED_RELEASE_FILES.findIndex(f=>Number(f.id)===Number(id));
 if(i>=0)window.SELECTED_RELEASE_FILES.splice(i,1); else window.SELECTED_RELEASE_FILES.push({id:Number(id),name:String(name),type:String(type||'')});
 renderSelectedReleaseFiles();
 showReleaseFileStoragePicker();
}
function removeReleaseFile(id){
 window.SELECTED_RELEASE_FILES=(window.SELECTED_RELEASE_FILES||[]).filter(f=>Number(f.id)!==Number(id));
 renderSelectedReleaseFiles();
}


/* CT4 global table sorting: every data table sorts locally without changing records. */
(function () {
  'use strict';
  function normalizedText(node) {
    if (!node) return '';
    if (node.matches && node.matches('input,select,textarea')) return String(node.value || '').trim();
    const control = node.querySelector && node.querySelector('input:not([type=hidden]),select,textarea');
    if (control) return String(control.value || '').trim();
    return String(node.innerText || node.textContent || '').replace(/\s+/g, ' ').trim();
  }
  function valueFor(cell) {
    if (!cell) return '';
    if (cell.dataset && cell.dataset.sortValue !== undefined) return cell.dataset.sortValue.trim();
    return normalizedText(cell);
  }
  function dateValue(value) {
    const s = String(value || '').trim();
    if (!s || !/(\d{4}[-/]\d{1,2}[-/]\d{1,2}|\d{1,2}[-/]\d{1,2}[-/]\d{2,4}|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*\b)/i.test(s)) return null;
    // MySQL DATETIME values are normalized to ISO-like values for consistent browser sorting.
    const normalized = /^\d{4}-\d{1,2}-\d{1,2}\s+\d{1,2}:\d{2}(?::\d{2})?$/.test(s) ? s.replace(/\s+/, 'T') : s;
    const n = Date.parse(normalized);
    return Number.isFinite(n) ? n : null;
  }
  function numericValue(value) {
    const s = String(value || '').trim().replace(/[,₱$%\s]/g, '');
    if (!s || !/^[-+]?(?:\d+\.?\d*|\.\d+)$/.test(s)) return null;
    const n = Number(s);
    return Number.isFinite(n) ? n : null;
  }
  function compareCells(a, b) {
    const av = valueFor(a), bv = valueFor(b);
    if (av === '' && bv !== '') return 1;
    if (bv === '' && av !== '') return -1;
    const ad = dateValue(av), bd = dateValue(bv);
    if (ad !== null && bd !== null) return ad - bd;
    const an = numericValue(av), bn = numericValue(bv);
    if (an !== null && bn !== null) return an - bn;
    return av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
  }
  function fitFiveRowScrollAreas() {
    document.querySelectorAll('.table-wrap.table-scroll-five').forEach(wrap => {
      const table = wrap.querySelector('table');
      const headRow = table && table.tHead && table.tHead.rows[0];
      const body = table && table.tBodies && table.tBodies[0];
      if (!headRow || !body) return;
      const rows = Array.from(body.rows).filter(row => {
        const cell = row.cells && row.cells[0];
        return !(cell && (cell.classList.contains('empty') || cell.colSpan > 1));
      });
      if (rows.length <= 5) { wrap.style.maxHeight = ''; return; }
      const headerHeight = headRow.getBoundingClientRect().height || 34;
      const rowsHeight = rows.slice(0, 5).reduce((total, row) => total + (row.getBoundingClientRect().height || 38), 0);
      wrap.style.maxHeight = Math.ceil(headerHeight + rowsHeight + 3) + 'px';
    });
  }
  function setupTable(table) {
    if (table.dataset.sortingReady === '1') return;
    const head = table.tHead;
    const body = table.tBodies && table.tBodies[0];
    if (!head || !head.rows.length || !body) return;
    table.dataset.sortingReady = '1';
    table.classList.add('is-sortable');
    Array.from(head.rows[0].cells).forEach((th, index) => {
      const label = String(th.textContent || '').replace(/\s+/g, ' ').trim();
      if (!label || /^(actions|edit|delete)$/i.test(label)) {
        th.dataset.sortable = 'false';
        return;
      }
      th.dataset.sortable = 'true';
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'table-sort-button';
      button.setAttribute('aria-label', 'Sort by ' + label);
      while (th.firstChild) button.appendChild(th.firstChild);
      const indicator = document.createElement('span');
      indicator.className = 'table-sort-indicator';
      indicator.setAttribute('aria-hidden', 'true');
      indicator.textContent = '↕';
      button.appendChild(indicator);
      th.appendChild(button);
      th.setAttribute('aria-sort', 'none');
      button.addEventListener('click', () => {
        const current = th.dataset.sortDirection || 'none';
        const direction = current === 'ascending' ? 'descending' : 'ascending';
        Array.from(head.rows[0].cells).forEach(other => {
          other.dataset.sortDirection = 'none';
          other.setAttribute('aria-sort', 'none');
          const mark = other.querySelector('.table-sort-indicator');
          if (mark) mark.textContent = '↕';
        });
        th.dataset.sortDirection = direction;
        th.setAttribute('aria-sort', direction);
        indicator.textContent = direction === 'ascending' ? '↑' : '↓';
        const dataRows = Array.from(body.rows).filter(row => {
          const firstCell = row.cells && row.cells[0];
          return !(firstCell && (firstCell.classList.contains('empty') || firstCell.colSpan > 1));
        });
        const emptyRows = Array.from(body.rows).filter(row => !dataRows.includes(row));
        dataRows.sort((ra, rb) => {
          const av = valueFor(ra.cells[index]), bv = valueFor(rb.cells[index]);
          if (av === '' && bv !== '') return 1;
          if (bv === '' && av !== '') return -1;
          let cmp;
          const sortType = String(th.dataset.sortType || '').toLowerCase();
          if (sortType === 'date' || sortType === 'datetime') {
            const da = dateValue(av), db = dateValue(bv);
            cmp = da !== null && db !== null ? da - db : av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
          } else if (sortType === 'number' || sortType === 'numeric') {
            const na = numericValue(av), nb = numericValue(bv);
            cmp = na !== null && nb !== null ? na - nb : av.localeCompare(bv, undefined, { numeric: true, sensitivity: 'base' });
          } else {
            cmp = compareCells(ra.cells[index], rb.cells[index]);
          }
          return (direction === 'ascending' ? 1 : -1) * cmp;
        });
        const frag = document.createDocumentFragment();
        dataRows.forEach(row => frag.appendChild(row));
        emptyRows.forEach(row => frag.appendChild(row));
        body.appendChild(frag);
        window.requestAnimationFrame(fitFiveRowScrollAreas);
      });
    });
  }
  function setupAllTables(root) {
    const scope = root && root.querySelectorAll ? root : document;
    if (scope.matches && scope.matches('table')) setupTable(scope);
    scope.querySelectorAll('table').forEach(setupTable);
    fitFiveRowScrollAreas();
  }
  // Expose the same global initializer so modules can explicitly request a refresh.
  window.CT4InitializeGlobalTableSorting = setupAllTables;
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', setupAllTables);
  else setupAllTables();
  // A second idempotent pass covers host pages that finish loading the shared script unusually late.
  window.addEventListener('load', setupAllTables, { once: true });
  // Keep global sorting available for tables inserted after initial page rendering.
  if ('MutationObserver' in window && document.documentElement) {
    const tableObserver = new MutationObserver(records => {
      records.forEach(record => record.addedNodes.forEach(node => {
        if (node.nodeType !== 1) return;
        if (node.matches && node.matches('table')) setupTable(node);
        if (node.querySelectorAll) node.querySelectorAll('table').forEach(setupTable);
      }));
      fitFiveRowScrollAreas();
    });
    tableObserver.observe(document.documentElement, { childList: true, subtree: true });
  }
  window.addEventListener('resize', fitFiveRowScrollAreas, { passive: true });
})();
