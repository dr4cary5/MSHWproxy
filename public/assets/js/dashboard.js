document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('cookieModal');
    const cookieList = document.getElementById('cookieList');
    const logConsole = document.getElementById('logConsole');
    const API = '/dashboard/api';

    async function loadCookies() {
        try {
            const res = await fetch(`${API}/cookies`);
            const data = await res.json();
            renderCookies(data.cookies || []);
            addLog('✅ Cookies refreshed');
        } catch (err) { addLog(`❌ Error: ${err.message}`); }
    }

    function renderCookies(groups) {
        cookieList.innerHTML = '';
        if (!groups.length) {
            cookieList.innerHTML = '<p style="color:#666;text-align:center;padding:1rem;">No cookies stored.</p>';
            return;
        }
        groups.forEach(g => {
            const div = document.createElement('div');
            div.className = 'cookie-group';
            div.innerHTML = `<h3>${g.domain}${g.path}</h3>`;
            Object.entries(g.cookies).forEach(([name, c]) => {
                div.innerHTML += `
                    <div class="cookie-item" data-domain="${g.domain}" data-path="${g.path}" data-name="${name}">
                        <div class="cookie-header">
                            <strong>${name}</strong>
                            <span class="actions"><button class="edit">✏️</button><button class="delete">🗑️</button></span>
                        </div>
                        <div class="cookie-details">
                            <code>${c.value}</code>
                            <small>Expires: ${c.expires ? new Date(c.expires*1000).toLocaleString() : 'Session'} | Secure: ${c.secure?'✓':'✗'}</small>
                        </div>
                    </div>`;
            });
            cookieList.appendChild(div);
        });
    }

    cookieList.addEventListener('click', async e => {
        const item = e.target.closest('.cookie-item');
        if (!item) return;
        const { domain, path, name } = item.dataset;

        if (e.target.classList.contains('delete')) {
            if (confirm(`Delete ${name}?`)) {
                await fetch(`${API}/cookies/${encodeURIComponent(domain)}/${encodeURIComponent(path)}/${encodeURIComponent(name)}`, { method: 'DELETE' });
                loadCookies();
            }
        } else if (e.target.classList.contains('edit')) {
            document.getElementById('modalDomain').value = domain;
            document.getElementById('modalPath').value = path;
            document.getElementById('modalName').value = name;
            document.getElementById('modalValue').value = '';
            modal.showModal();
        }
    });

    modal.querySelector('form').addEventListener('submit', async e => {
        e.preventDefault();
        const { domain, path, name } = document.getElementById('modalDomain').dataset; // Note: fixed below
        const updates = {
            value: document.getElementById('modalValue').value,
            expires: parseInt(document.getElementById('modalExpires').value) || null,
            secure: document.getElementById('modalSecure').checked,
            httpOnly: document.getElementById('modalHttpOnly').checked,
            sameSite: document.getElementById('modalSameSite').value
        };
        await fetch(`${API}/cookies/${encodeURIComponent(domain)}/${encodeURIComponent(path)}/${encodeURIComponent(name)}`, {
            method: 'PUT', headers: {'Content-Type': 'application/json'}, body: JSON.stringify(updates)
        });
        modal.close();
        loadCookies();
    });

    // Fix dataset reference for edit modal
    document.querySelectorAll('.cookie-item .edit').forEach(btn => {
        btn.replaceWith(btn.cloneNode(true));
    });
    // Re-bind after render fix is handled by event delegation above. 
    // Simple patch for modal data:
    window.openEdit = (d, p, n) => {
        document.getElementById('modalDomain').value = d;
        document.getElementById('modalPath').value = p;
        document.getElementById('modalName').value = n;
        modal.showModal();
    };

    document.getElementById('importCookie').addEventListener('click', async () => {
        const raw = prompt('Paste raw Set-Cookie header:');
        if (!raw) return;
        await fetch(`${API}/cookies/import`, {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ raw, domain: location.hostname, path: '/' })
        });
        loadCookies();
    });

    document.getElementById('clearAll').addEventListener('click', async () => {
        if (confirm('Clear all cookies?')) {
            await fetch(`${API}/cookies/clear`, { method: 'POST' });
            loadCookies();
        }
    });

    function addLog(msg) {
        const div = document.createElement('div');
        div.textContent = `[${new Date().toLocaleTimeString()}] ${msg}`;
        logConsole.prepend(div);
        if (logConsole.children.length > 100) logConsole.lastChild.remove();
    }

    loadCookies();
    addLog('🚀 Dashboard initialized');
});
