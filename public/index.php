<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Analyse des relevés de notes — Licence</title>
<style>
:root { color-scheme: light; }
* { box-sizing: border-box; }
body { font-family: system-ui, -apple-system, sans-serif; margin: 0; background: #f6f7f9; color: #1c2430; min-height: 100vh; }
.wrap { max-width: 640px; margin: 0 auto; padding: 60px 20px; }
h1 { font-size: 24px; margin: 0 0 6px; }
.sub { color: #5c6a7d; font-size: 15px; margin-bottom: 28px; }
.drop { border: 2px dashed #b9c3d0; border-radius: 12px; background: #fff; padding: 32px 24px; text-align: center; cursor: pointer; transition: border-color .15s, background .15s; display: block; }
.drop.over { border-color: #2563eb; background: #eff6ff; }
.drop .big { font-size: 36px; margin-bottom: 8px; }
.drop p { margin: 6px 0; color: #5c6a7d; font-size: 14px; }
.drop .fname { color: #1c2430; font-weight: 600; font-size: 15px; }
input[type=file] { display: none; }
.extra { margin: 22px 0 6px; }
.lbl { display: block; font-size: 14px; font-weight: 600; margin-bottom: 6px; }
.redou-drop { display: block; border: 1.5px dashed #c7d0dc; border-radius: 9px; padding: 12px 16px; cursor: pointer; font-size: 13.5px; color: #5c6a7d; background: #fff; transition: border-color .15s, background .15s; }
.redou-drop.over { border-color: #2563eb; background: #eff6ff; }
.redou-drop .fname { color: #1c2430; font-weight: 600; }
textarea#redou { width: 100%; min-height: 90px; border: 1px solid #b9c3d0; border-radius: 8px; padding: 10px; font: 13px/1.6 ui-monospace, SFMono-Regular, Menlo, monospace; resize: vertical; margin-top: 8px; }
.hint { font-size: 12.5px; color: #7b8798; margin: 6px 0 0; line-height: 1.5; }
details { margin-top: 10px; }
details summary { cursor: pointer; }
.btn { display: inline-block; margin-top: 18px; padding: 10px 22px; border-radius: 9px; border: 0; background: #2563eb; color: #fff; font-size: 15px; cursor: pointer; }
.btn:disabled { background: #a7b6d8; cursor: not-allowed; }
.filechips { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 12px; }
.chip { display: inline-flex; align-items: center; gap: 8px; background: #eef3fb; border: 1px solid #cdd8ea; border-radius: 8px; padding: 6px 10px; font-size: 13px; }
.chip button { border: 0; background: transparent; cursor: pointer; font-size: 16px; color: #5c6a7d; line-height: 1; padding: 0; }
.progress { display: none; margin-top: 18px; }
.progress.show { display: block; }
.bar { height: 8px; background: #e3e8ef; border-radius: 4px; overflow: hidden; }
.bar > div { height: 100%; width: 0; background: #2563eb; transition: width .2s; }
.status { margin-top: 10px; font-size: 14px; color: #5c6a7d; }
.status.err { color: #b91c1c; }
.note { margin-top: 30px; font-size: 13px; color: #7b8798; line-height: 1.5; }
</style>
</head>
<body>
<div class="wrap">
<h1>Analyse des PV de semestre</h1>
<p class="sub">Licence — fichiers de PV de semestre (format APO_PVLxSyPARCOURS.xlsx). Ajoutez les fichiers un par un ou par lot. 1 fichier = analyse d'un parcours · plusieurs = vue globale. Les fichiers sont traités sur le serveur puis supprimés.</p>

<label id="drop" class="drop">
<span class="big">📄</span>
<p><strong>Ajouter des fichiers .xlsx</strong> (un ou plusieurs, ajout répété possible) ou cliquez pour choisir</p>
<p class="fname" id="fname"></p>
<input type="file" id="file" accept=".xlsx" multiple>
</label>
<div class="filechips" id="fileChips"></div>

<div class="extra">
<label class="lbl" for="redouFile">Liste des redoublants (optionnel, commune à tous les parcours)</label>
<label id="redouDrop" class="redou-drop">
<span class="fname" id="redouFname">Fichier Excel / CSV avec la colonne des identifiants (en-tête tolérée)</span>
<input type="file" id="redouFile" accept=".xlsx,.xls,.csv">
</label>
<p class="hint">Seuls les identifiants présents dans chaque fichier de notes seront analysés ; les autres (autres parcours) sont ignorés.</p>
<details>
<summary class="hint">Ou saisie manuelle des identifiants (sans fichier)</summary>
<textarea id="redou" placeholder="Un identifiant par ligne (ou séparés par des espaces / virgules)…"></textarea>
</details>
</div>

<button class="btn" id="go" disabled>Analyser</button>

<div class="progress" id="progress">
<div class="bar"><div id="bar"></div></div>
<div class="status" id="status"></div>
</div>

<p class="note">
L'analyse reste disponible 24 h via un lien unique (aucune base de données).
Les noms et prénoms sont conservés dans les listes « jury » (absences, matières à corriger).
</p>
</div>
<script>
const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
let selectedFiles = [];
let selectedRedouFile = null;

const drop = document.getElementById('drop');
const input = document.getElementById('file');
const fname = document.getElementById('fname');
const chips = document.getElementById('fileChips');
const redouDrop = document.getElementById('redouDrop');
const redouFile = document.getElementById('redouFile');
const redouFname = document.getElementById('redouFname');
const redou = document.getElementById('redou');
const btn = document.getElementById('go');
const progress = document.getElementById('progress');
const bar = document.getElementById('bar');
const status = document.getElementById('status');

function addFiles(list) {
  const incoming = Array.from(list || []);
  let bad = false;
  incoming.forEach((f) => {
    if (!f.name.toLowerCase().endsWith('.xlsx')) {
      status.textContent = 'Format non supporté : .xlsx requis (« ' + f.name + ' »)';
      status.className = 'status err';
      progress.classList.add('show');
      bad = true;
      return;
    }
    const dup = selectedFiles.some((x) => x.name === f.name && x.size === f.size);
    if (!dup) selectedFiles.push(f);
  });
  if (!bad) renderChips();
}

function renderChips() {
  fname.textContent = selectedFiles.length ? selectedFiles.length + ' fichier(s) sélectionné(s)' : '';
  chips.innerHTML = selectedFiles.map((f, i) =>
    '<span class="chip">' + esc(f.name) + ' <button data-i="' + i + '" title="Retirer">×</button></span>'
  ).join('');
  chips.querySelectorAll('.chip button').forEach((b) => {
    b.addEventListener('click', () => { selectedFiles.splice(Number(b.dataset.i), 1); renderChips(); });
  });
  btn.disabled = selectedFiles.length === 0;
  btn.textContent = selectedFiles.length === 1 ? 'Analyser le fichier' : ('Analyser les ' + selectedFiles.length + ' parcours');
}

function setRedouFile(f) {
  if (!f) return;
  if (!/\.(xlsx|xls|csv)$/i.test(f.name)) {
    status.textContent = 'Fichier redoublants : .xlsx, .xls ou .csv requis.';
    status.className = 'status err';
    progress.classList.add('show');
    return;
  }
  selectedRedouFile = f;
  redouFname.textContent = f.name + ' — ' + (f.size / 1048576).toFixed(2) + ' Mo';
}

drop.addEventListener('dragover', (e) => { e.preventDefault(); drop.classList.add('over'); });
drop.addEventListener('dragleave', () => drop.classList.remove('over'));
drop.addEventListener('drop', (e) => { e.preventDefault(); drop.classList.remove('over'); addFiles(e.dataTransfer.files); });
input.addEventListener('change', () => { addFiles(input.files); input.value = ''; });

redouDrop.addEventListener('dragover', (e) => { e.preventDefault(); redouDrop.classList.add('over'); });
redouDrop.addEventListener('dragleave', () => redouDrop.classList.remove('over'));
redouDrop.addEventListener('drop', (e) => { e.preventDefault(); redouDrop.classList.remove('over'); setRedouFile(e.dataTransfer.files[0] ?? null); });
redouFile.addEventListener('change', () => setRedouFile(redouFile.files[0] ?? null));

btn.addEventListener('click', () => {
  if (!selectedFiles.length) {
    status.textContent = 'Sélectionnez d’abord un ou plusieurs fichiers .xlsx.';
    status.className = 'status err';
    progress.classList.add('show');
    return;
  }
  const multi = selectedFiles.length > 1;
  const fd = new FormData();
  if (multi) { selectedFiles.forEach((f) => fd.append('apofiles[]', f)); }
  else { fd.append('apofile', selectedFiles[0]); }
  if (selectedRedouFile) fd.append('redoufile', selectedRedouFile);
  else if (redou.value.trim() !== '') fd.append('redoublants', redou.value);

  btn.disabled = true;
  progress.classList.add('show');
  status.className = 'status';
  status.textContent = 'Téléversement et traitement…';
  bar.style.width = '0%';

  const xhr = new XMLHttpRequest();
  xhr.open('POST', multi ? 'api/upload_multi.php' : 'api/upload.php');
  xhr.upload.onprogress = (e) => { if (e.lengthComputable) bar.style.width = (e.loaded / e.total * 100).toFixed(0) + '%'; };
  xhr.onload = () => {
    let r;
    try { r = JSON.parse(xhr.responseText); } catch (e) { r = { ok: false, error: 'Réponse serveur inattendue.' }; }
    if (r.ok) {
      status.textContent = 'Analyse terminée — chargement de la page…';
      location.href = (multi ? 'global.php' : 'analyse.php') + '?token=' + r.token;
    } else {
      status.textContent = 'Erreur : ' + r.error;
      status.className = 'status err';
      btn.disabled = false;
    }
  };
  xhr.onerror = () => { status.textContent = 'Erreur réseau lors du téléversement.'; status.className = 'status err'; btn.disabled = false; };
  xhr.send(fd);
});
</script>
</body>
</html>