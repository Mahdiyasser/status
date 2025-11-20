<?php
// Set a default timezone
date_default_timezone_set('UTC');

// --- PATH CONFIGURATION ---
$data_file = __DIR__ . '/../data/data.json';

// --- HARDCODED DEFAULTS (Safety Mechanism) ---
$DEFAULTS = [
    ['type' => 'all good', 'label' => 'Operational', 'color' => '#22c55e'],
    ['type' => 'minor issue', 'label' => 'Minor Issue', 'color' => '#6366f1'],
    ['type' => 'working on it', 'label' => 'Degraded Performance', 'color' => '#f97316'],
    ['type' => 'maintenance', 'label' => 'Maintenance', 'color' => '#38bdf8'],
    ['type' => 'danger', 'label' => 'Major Outage', 'color' => '#ef4444'],
    ['type' => 'monitoring', 'label' => 'Monitoring', 'color' => '#10b981']
];

// --- DATA HANDLING FUNCTIONS (Global Scope) ---
function get_json_data($path) {
    global $DEFAULTS;
    if (!file_exists($path)) {
        $data = ['overall_message' => 'System Operational', 'statuses' => [], 'custom_labels' => []];
    } else {
        $content = file_get_contents($path);
        $data = json_decode($content, true);
        if (!is_array($data)) $data = ['overall_message' => 'System Operational', 'statuses' => [], 'custom_labels' => []];
    }
    
    // ENFORCE DEFAULTS
    $custom_only = array_filter($data['custom_labels'] ?? [], function($l) use ($DEFAULTS) {
        foreach ($DEFAULTS as $d) {
            if ($d['type'] === $l['type']) return false;
        }
        return true;
    });
    
    $data['custom_labels'] = array_merge($DEFAULTS, array_values($custom_only));
    return $data;
}

function save_json_data($path, $data) {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

// --- API HANDLING ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])) {
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *'); 

    try {
        $data = get_json_data($data_file);
        
        $data['statuses'] = $data['statuses'] ?? [];
        $data['overall_message'] = $data['overall_message'] ?? 'All Systems Operational';

        if ($_GET['action'] === 'save') {
            $id = $_POST['id'] ?: uniqid();
            $new_status = [
                'id' => $id,
                'title' => $_POST['title'] ?? 'No Title',
                'type' => $_POST['status_type'] ?? 'all good',
                'label_override' => $_POST['label_override'] ?? '',
                'description' => $_POST['description'] ?? '',
                'timestamp' => date('Y-m-d H:i:s')
            ];

            $found = false;
            foreach ($data['statuses'] as $k => $v) {
                if ($v['id'] === $id) {
                    $data['statuses'][$k] = $new_status;
                    $found = true;
                    break;
                }
            }
            if (!$found) array_unshift($data['statuses'], $new_status);

            if (save_json_data($data_file, $data)) {
                echo json_encode(['status' => 'success', 'message' => 'Saved successfully']);
            } else {
                throw new Exception('Could not write to data file.');
            }

        } elseif ($_GET['action'] === 'delete') {
            $id = $_POST['id'];
            $data['statuses'] = array_values(array_filter($data['statuses'], function($s) use ($id) {
                return $s['id'] !== $id;
            }));
            save_json_data($data_file, $data);
            echo json_encode(['status' => 'success', 'message' => 'Deleted successfully']);

        } elseif ($_GET['action'] === 'reorder') {
            $order = json_decode($_POST['order'] ?? '[]', true);
            $user_custom_labels = json_decode($_POST['custom_labels'] ?? '[]', true);
            
            $data['overall_message'] = $_POST['overall_message'];
            
            $clean_custom = array_filter($user_custom_labels, function($l) use ($DEFAULTS) {
                foreach ($DEFAULTS as $d) {
                    if ($d['type'] === $l['type']) return false;
                }
                return true;
            });
            $data['custom_labels'] = array_merge($DEFAULTS, $clean_custom);

            if (!empty($order)) {
                $map = array_column($data['statuses'], null, 'id');
                $new_statuses = [];
                foreach ($order as $id) {
                    if (isset($map[$id])) {
                        $new_statuses[] = $map[$id];
                        unset($map[$id]);
                    }
                }
                $data['statuses'] = array_merge($new_statuses, array_values($map));
            }
            
            save_json_data($data_file, $data);
            echo json_encode(['status' => 'success', 'message' => 'Configuration saved']);

        } elseif ($_GET['action'] === 'load_data') {
            echo json_encode($data);
        }

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}

// --- HTML RENDER ---
ob_start();
// Now get_json_data is defined and available here
$ui_data = get_json_data($data_file);
$ui_defaults = $DEFAULTS;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Status CMS</title>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        body { font-family: -apple-system, system-ui, sans-serif; background: #f3f4f6; padding: 20px; margin: 0; color: #1f2937; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; padding: 25px; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin-bottom: 25px; border: 1px solid #e5e7eb; }
        h1 { font-size: 1.8rem; margin-bottom: 25px; border-bottom: 2px solid #e5e7eb; padding-bottom: 15px; color: #111827; }
        h2 { font-size: 1.3rem; margin-top: 0; margin-bottom: 20px; color: #374151; }
        
        .input-group { margin-bottom: 20px; }
        label { display: block; font-weight: 600; font-size: 0.9rem; margin-bottom: 8px; color: #4b5563; }
        input[type="text"], select { width: 100%; padding: 10px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 1rem; box-sizing: border-box; transition: all 0.2s; }
        input:focus, select:focus { outline: 2px solid #2563eb; border-color: transparent; background: #fff; }
        
        /* --- ENHANCED EDITOR STYLES --- */
        .editor-wrapper { border: 1px solid #d1d5db; border-radius: 6px; overflow: hidden; background: white; transition: box-shadow 0.2s; }
        .editor-wrapper:focus-within { box-shadow: 0 0 0 2px #2563eb; border-color: transparent; }
        .editor-toolbar { background: #f3f4f6; border-bottom: 1px solid #e5e7eb; padding: 8px; display: flex; gap: 4px; flex-wrap: wrap; align-items: center; }
        .editor-btn { background: white; border: 1px solid #d1d5db; padding: 6px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85rem; color: #374151; font-weight: 600; transition: all 0.1s; min-width: 32px; text-align: center; }
        .editor-btn:hover { background: #e5e7eb; color: #111827; }
        .editor-separator { border-right: 1px solid #d1d5db; margin: 0 6px; height: 20px; }
        #description-editor { min-height: 180px; padding: 15px; outline: none; font-size: 1rem; line-height: 1.6; color: #1f2937; }
        #description-editor ul, #description-editor ol { padding-left: 25px; margin: 10px 0; }
        #description-editor a { color: #2563eb; text-decoration: underline; }
        
        .btn { padding: 10px 20px; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; font-size: 0.95rem; color: white; transition: opacity 0.2s; }
        .btn:hover { opacity: 0.9; }
        .btn-blue { background: #2563eb; }
        .btn-green { background: #059669; }
        .btn-red { background: #dc2626; }
        .btn-gray { background: #6b7280; color: white; }
        
        .status-item { display: flex; align-items: center; padding: 15px; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; background: #fff; transition: transform 0.1s; }
        .status-item:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .drag-handle { cursor: grab; margin-right: 15px; color: #9ca3af; font-size: 1.2rem; padding: 5px; }
        .status-info { flex: 1; }
        .status-title { font-weight: 700; font-size: 1.1rem; color: #111827; }
        .status-meta { font-size: 0.85rem; color: #6b7280; margin-top: 4px; }
        .status-pill { padding: 2px 8px; border-radius: 99px; font-size: 0.75rem; color: white; margin-left: 10px; vertical-align: middle; display: inline-block; text-shadow: 0 1px 2px rgba(0,0,0,0.1); }
        
        #message-box { position: fixed; bottom: 20px; right: 20px; padding: 15px 25px; border-radius: 8px; color: white; display: none; font-weight: 600; z-index: 100; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .msg-success { background: #059669; } .msg-error { background: #dc2626; }
        
        .label-row { display: flex; gap: 10px; margin-bottom: 10px; align-items: center; background: #f9fafb; padding: 10px; border-radius: 6px; border: 1px solid #e5e7eb; }
        .label-row input { flex: 1; background: white; }
        .label-row input[type="color"] { flex: 0 0 40px; padding: 0; height: 40px; cursor: pointer; border: none; border-radius: 4px; overflow: hidden; }
    </style>
</head>
<body>

<div id="message-box"></div>

<div class="container">
    <h1>System Status CMS</h1>

    <!-- 1. CONFIG FORM -->
    <div class="card">
        <h2>Global Settings & Custom Types</h2>
        <form id="config-form">
            <div class="input-group">
                <label>Main Banner Message</label>
                <input type="text" name="overall_message" value="<?= htmlspecialchars($ui_data['overall_message'] ?? '') ?>" required>
            </div>
            
            <div class="input-group">
                <label>Custom Status Types</label>
                <p style="font-size:0.85rem; color:#6b7280; margin-bottom:15px;">
                    <b>Type:</b> Internal ID. <b>Label:</b> Public Name. <br>
                    <i>Note: Default types (Operational, Major Outage, etc.) are protected and not shown here, but will appear in the dropdown.</i>
                </p>
                <div id="custom-labels-container"></div>
                <button type="button" class="btn btn-gray" onclick="addLabelRow()" style="margin-top:10px; font-size:0.85rem;">+ Add Custom Type</button>
                <input type="hidden" name="custom_labels" id="custom_labels_input">
            </div>
            <button type="submit" class="btn btn-green" onclick="saveConfig(event)">Save Settings</button>
        </form>
    </div>

    <!-- 2. STATUS FORM -->
    <div class="card">
        <h2 id="form-title">Create New Update</h2>
        <form id="status-form">
            <input type="hidden" name="id" id="status_id">
            <div class="input-group">
                <label>Title</label>
                <input type="text" name="title" id="title" placeholder="e.g. Database Connectivity Issue" required>
            </div>
            
            <!-- Flex Container for Type and Custom Label -->
            <div style="display: flex; gap: 20px; flex-wrap: wrap;">
                <div class="input-group" style="flex: 1;">
                    <label>Status Type (Base Color & Name)</label>
                    <select name="status_type" id="status_type"></select>
                </div>
                <div class="input-group" style="flex: 1;">
                    <label>Custom Label Override (Optional)</label>
                    <input type="text" name="label_override" id="label_override" placeholder="e.g. Fixing (Overrides 'Major Outage')">
                </div>
            </div>

            <div class="input-group">
                <label>Description</label>
                <div class="editor-wrapper">
                    <div class="editor-toolbar">
                        <button type="button" class="editor-btn" onclick="format('bold')" title="Bold"><b>B</b></button>
                        <button type="button" class="editor-btn" onclick="format('italic')" title="Italic"><i>I</i></button>
                        <button type="button" class="editor-btn" onclick="format('underline')" title="Underline"><u>U</u></button>
                        <span class="editor-separator"></span>
                        <button type="button" class="editor-btn" onclick="format('formatBlock', 'H3')" title="Heading">Heading</button>
                        <button type="button" class="editor-btn" onclick="format('insertUnorderedList')" title="Bullet List">• List</button>
                        <button type="button" class="editor-btn" onclick="format('insertOrderedList')" title="Numbered List">1. List</button>
                        <span class="editor-separator"></span>
                        <button type="button" class="editor-btn" onclick="format('createLink')" title="Link">Link</button>
                    </div>
                    <div id="description-editor" contenteditable="true"></div>
                </div>
                <textarea name="description" id="description" style="display:none;"></textarea>
            </div>
            <button type="submit" class="btn btn-blue" id="save-btn">Post Update</button>
            <button type="button" class="btn btn-gray" id="cancel-btn" style="display:none" onclick="resetForm()">Cancel</button>
        </form>
    </div>

    <!-- 3. LIST -->
    <div class="card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
            <h2>Active Updates</h2>
            <button type="button" class="btn btn-blue" onclick="saveOrder()">Save Order</button>
        </div>
        <div id="status-list"></div>
    </div>
</div>

<script>
    const API_ENDPOINT = 'index.php'; 
    
    // Data from PHP
    let serverData = <?= json_encode($ui_data) ?>;
    let hardcodedDefaults = <?= json_encode($ui_defaults) ?>;
    
    let statuses = serverData.statuses || [];
    let allLabels = serverData.custom_labels || []; 

    // --- RENDER DROPDOWN OPTIONS ---
    function renderStatusOptions() {
        const select = document.getElementById('status_type');
        const currentVal = select.value; 
        select.innerHTML = '';
        
        // Get Labels from UI inputs (live editing)
        const uiCustomLabels = getLabelsFromUI();
        
        // Combine Hardcoded Defaults + UI Custom Labels
        const combined = [...hardcodedDefaults, ...uiCustomLabels];

        combined.forEach(item => {
            const opt = document.createElement('option');
            opt.value = item.type;
            opt.textContent = item.label;
            select.appendChild(opt);
        });

        if (currentVal) select.value = currentVal;
    }

    function getLabelsFromUI() {
        const inputs = document.querySelectorAll('.label-row');
        const labels = [];
        inputs.forEach(row => {
            const t = row.querySelector('.lbl-type').value.trim();
            const l = row.querySelector('.lbl-text').value.trim();
            const c = row.querySelector('.lbl-color').value;
            if(t) labels.push({type: t, label: l, color: c});
        });
        return labels;
    }

    // --- RENDER LIST ---
    function renderList() {
        const container = document.getElementById('status-list');
        container.innerHTML = '';
        statuses.forEach(s => {
            const div = document.createElement('div');
            div.className = 'status-item';
            div.dataset.id = s.id;
            
            // Determine Display Label
            const baseLabel = getLabelFromType(s.type);
            const displayLabel = s.label_override ? s.label_override : baseLabel;
            const color = getColorFromType(s.type);
            
            div.innerHTML = `
                <span class="drag-handle">☰</span>
                <div class="status-info">
                    <div class="status-title">${s.title} <span class="status-pill" style="background:${color}">${displayLabel}</span></div>
                    <div class="status-meta">${s.timestamp}</div>
                </div>
                <div>
                    <button class="btn btn-blue" style="padding:6px 12px; font-size:0.8rem" onclick='editStatus("${s.id}")'>Edit</button>
                    <button class="btn btn-red" style="padding:6px 12px; font-size:0.8rem" onclick='deleteStatus("${s.id}")'>Delete</button>
                </div>
            `;
            container.appendChild(div);
        });
    }

    function getColorFromType(type) {
        const live = getLabelsFromUI();
        const all = [...hardcodedDefaults, ...live];
        const found = all.find(i => i.type === type);
        return found ? found.color : '#999';
    }

    function getLabelFromType(type) {
        const live = getLabelsFromUI();
        const all = [...hardcodedDefaults, ...live];
        const found = all.find(i => i.type === type);
        return found ? found.label : type;
    }

    // --- RENDER CONFIG LABELS (Exclude Defaults) ---
    function renderLabels() {
        const container = document.getElementById('custom-labels-container');
        container.innerHTML = '';
        
        // Filter out defaults so they aren't editable
        const editableLabels = allLabels.filter(l => {
            return !hardcodedDefaults.some(d => d.type === l.type);
        });

        editableLabels.forEach((l) => {
            addLabelRow(l.type, l.label, l.color);
        });
    }

    function addLabelRow(type='', label='', color='#000000') {
        const container = document.getElementById('custom-labels-container');
        const div = document.createElement('div');
        div.className = 'label-row';
        div.innerHTML = `
            <input type="text" class="lbl-type" value="${type}" placeholder="Type (e.g. dead)" oninput="renderStatusOptions()">
            <input type="text" class="lbl-text" value="${label}" placeholder="Label (e.g. Fixing)" oninput="renderStatusOptions()">
            <input type="color" class="lbl-color" value="${color}" oninput="renderStatusOptions()">
            <button type="button" class="btn btn-red" style="padding:8px 12px" onclick="removeRow(this)">X</button>
        `;
        container.appendChild(div);
        renderStatusOptions(); 
    }
    
    function removeRow(btn) {
        btn.parentElement.remove();
        renderStatusOptions();
    }

    // --- EDITOR LOGIC ---
    function format(cmd, val=null) {
        if(cmd === 'createLink') {
            val = prompt("Enter URL:");
            if(!val) return;
        }
        document.execCommand(cmd, false, val);
        document.getElementById('description-editor').focus();
    }

    // --- API ACTIONS ---
    async function apiCall(action, formData) {
        try {
            const res = await fetch(API_ENDPOINT + '?action=' + action, { method:'POST', body:formData });
            if (!res.ok) throw new Error('Server Error ' + res.status);
            const text = await res.text();
            try {
                return JSON.parse(text);
            } catch(e) {
                console.error("Bad JSON:", text);
                throw new Error("Server returned invalid JSON");
            }
        } catch(err) {
            alert(err.message);
            return { status:'error' };
        }
    }

    // SAVE STATUS
    document.getElementById('status-form').onsubmit = async (e) => {
        e.preventDefault();
        document.getElementById('description').value = document.getElementById('description-editor').innerHTML;
        const res = await apiCall('save', new FormData(e.target));
        if(res.status === 'success') window.location.reload();
    };

    // CONFIG
    async function saveConfig(e) {
        e.preventDefault();
        const inputs = document.querySelectorAll('.label-row');
        const newLabels = [];
        inputs.forEach(row => {
            const type = row.querySelector('.lbl-type').value.trim();
            const label = row.querySelector('.lbl-text').value.trim();
            if(type && label) {
                newLabels.push({
                    type: type,
                    label: label,
                    color: row.querySelector('.lbl-color').value
                });
            }
        });
        
        const fd = new FormData(document.getElementById('config-form'));
        fd.append('custom_labels', JSON.stringify(newLabels));
        
        const sortIds = Array.from(document.getElementById('status-list').children).map(el => el.dataset.id);
        fd.append('order', JSON.stringify(sortIds));

        const res = await apiCall('reorder', fd); 
        if(res.status === 'success') {
            showMsg('Settings Saved!');
            setTimeout(() => window.location.reload(), 1000);
        }
    }

    // EDIT & DELETE
    async function deleteStatus(id) {
        if(!confirm('Delete?')) return;
        const fd = new FormData(); fd.append('id', id);
        const res = await apiCall('delete', fd);
        if(res.status === 'success') window.location.reload();
    }

    function editStatus(id) {
        const s = statuses.find(x => x.id === id);
        document.getElementById('status_id').value = s.id;
        document.getElementById('title').value = s.title;
        document.getElementById('status_type').value = s.type;
        document.getElementById('label_override').value = s.label_override || '';
        document.getElementById('description-editor').innerHTML = s.description; 
        
        document.getElementById('form-title').textContent = "Edit Update";
        document.getElementById('save-btn').textContent = "Update";
        document.getElementById('cancel-btn').style.display = 'inline-block';
        document.getElementById('status-form').scrollIntoView({behavior:'smooth'});
    }

    function resetForm() {
        document.getElementById('status-form').reset();
        document.getElementById('description-editor').innerHTML = '';
        document.getElementById('status_id').value = '';
        document.getElementById('label_override').value = '';
        document.getElementById('form-title').textContent = "Create New Update";
        document.getElementById('save-btn').textContent = "Post Update";
        document.getElementById('cancel-btn').style.display = 'none';
    }

    function saveOrder() {
         document.querySelector('#config-form button[type="submit"]').click();
    }

    function showMsg(txt) {
        const box = document.getElementById('message-box');
        box.textContent = txt; box.className = 'msg-success'; box.style.display = 'block';
        setTimeout(() => box.style.display = 'none', 2000);
    }

    // INIT
    new Sortable(document.getElementById('status-list'), { handle: '.drag-handle', animation: 150 });
    renderLabels();
    renderStatusOptions(); 
    renderList();
</script>
</body>
</html>
