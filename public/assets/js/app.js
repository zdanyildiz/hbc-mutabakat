document.addEventListener('DOMContentLoaded', () => {
    // Dropzones
    const excelDropzone = document.getElementById('excelDropzone');
    const pdfDropzone = document.getElementById('pdfDropzone');
    const excelInput = document.getElementById('excel_file');
    const pdfInput = document.getElementById('pdf_file');

    // Setup drag and drop
    setupDropzone(excelDropzone, excelInput);
    setupDropzone(pdfDropzone, pdfInput);

    function setupDropzone(dropzone, input) {
        const content = dropzone.querySelector('.dropzone-content');
        const info = dropzone.querySelector('.selected-file-info');
        const fileNameSpan = dropzone.querySelector('.file-name');
        const removeBtn = dropzone.querySelector('.remove-file-btn');

        // Click to open file dialog
        dropzone.addEventListener('click', (e) => {
            if (e.target !== removeBtn && !info.contains(e.target)) {
                input.click();
            }
        });

        // File selected via dialog
        input.addEventListener('change', () => {
            if (input.files && input.files.length > 0) {
                showFile(input.files);
            }
        });

        // Drag events
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.add('dragover');
            }, false);
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                dropzone.classList.remove('dragover');
            }, false);
        });

        // Drop file
        dropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                if (input.multiple) {
                    input.files = dt.files;
                } else {
                    const singleFileDT = new DataTransfer();
                    singleFileDT.items.add(dt.files[0]);
                    input.files = singleFileDT.files;
                }
                showFile(input.files);
                // Trigger change event manually
                input.dispatchEvent(new Event('change'));
            }
        });

        // Remove file
        removeBtn.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            input.value = '';
            info.style.display = 'none';
            content.style.display = 'flex';
            dropzone.classList.remove('has-file');
        });

        function showFile(files) {
            if (files.length > 1) {
                fileNameSpan.textContent = `${files.length} PDF dosyası seçildi`;
            } else if (files.length === 1) {
                fileNameSpan.textContent = files[0].name;
            } else {
                fileNameSpan.textContent = '';
            }
            content.style.display = 'none';
            info.style.display = 'flex';
            dropzone.classList.add('has-file');
        }
    }

    // Reconciliation logic
    const form = document.getElementById('reconcileForm');
    const submitBtn = document.getElementById('submitBtn');
    const btnText = submitBtn.querySelector('.btn-text');
    const loader = submitBtn.querySelector('.loader');

    const resultsSection = document.getElementById('resultsSection');
    const resultStoreName = document.getElementById('resultStoreName');
    const statMatchedVal = document.getElementById('statMatchedVal');
    const statMissingVal = document.getElementById('statMissingVal');
    const statExtraVal = document.getElementById('statExtraVal');
    
    const countAll = document.getElementById('countAll');
    const countMissing = document.getElementById('countMissing');
    const countExtra = document.getElementById('countExtra');
    const countMatched = document.getElementById('countMatched');
    
    const tableBody = document.getElementById('tableBody');
    const noDataMsg = document.getElementById('noDataMsg');
    const tableSearch = document.getElementById('tableSearch');
    const filterButtons = document.querySelectorAll('.tab-btn');
    const downloadExcelBtn = document.getElementById('downloadExcelBtn');
    const showRawDataBtn = document.getElementById('showRawDataBtn');
    const statSuspectedVal = document.getElementById('statSuspectedVal');
    const countSuspected = document.getElementById('countSuspected');
    const reconciliationTime = document.getElementById('reconciliationTime');

    let currentData = {
        storeName: '',
        matched: [],
        missingInStore: [],
        extraInStore: [],
        barcodeStores: {},
        pdfOriginalWords: {},
        pdfMismatches: [],
        suspectedMatches: [],
        terminalBarcodes: [],
        storeBarcodes: []
    };

    let activeFilter = 'all';
    let allRows = []; // Tablodaki tüm satırların listesi (Excel indirme ve sıralama için kullanılacak)

    const liveTimer = document.getElementById('liveTimer');
    let timerInterval = null;
    let secondsElapsed = 0;

    form.addEventListener('submit', (e) => {
        e.preventDefault();

        // Show loading state
        submitBtn.disabled = true;
        btnText.style.display = 'none';
        loader.style.display = 'block';

        // Reset and start live timer
        secondsElapsed = 0;
        if (liveTimer) {
            liveTimer.textContent = '0.0 sn';
            liveTimer.style.display = 'inline-block';
        }
        if (timerInterval) {
            clearInterval(timerInterval);
        }
        timerInterval = setInterval(() => {
            secondsElapsed += 0.1;
            if (liveTimer) {
                liveTimer.textContent = `${secondsElapsed.toFixed(1)} sn`;
            }
        }, 100);

        const formData = new FormData(form);

        fetch('index.php?action=reconcile', {
            method: 'POST',
            body: formData
        })
        .then(response => response.text())
        .then(text => {
            const cleanedText = text.trim();
            const data = JSON.parse(cleanedText);
            if (data.success) {
                // Save data locally for rendering and exporting
                currentData.storeName = data.store_name;
                currentData.matched = data.result.matched;
                currentData.missingInStore = data.result.missingInStore;
                currentData.extraInStore = data.result.extraInStore;
                currentData.barcodeStores = data.barcode_stores || {};
                currentData.pdfOriginalWords = data.pdf_original_words || {};
                currentData.pdfMismatches = data.pdf_mismatches || [];
                currentData.suspectedMatches = data.result.suspectedMatches || [];
                currentData.terminalBarcodes = data.result.terminalBarcodes || [];
                currentData.storeBarcodes = data.result.storeBarcodes || [];
                currentData.elapsedTime = data.elapsed_time || null;

                renderResults();
                resultsSection.scrollIntoView({ behavior: 'smooth' });
            } else {
                alert('Hata: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('İşlem sırasında beklenmedik bir hata oluştu.');
        })
        .finally(() => {
            // Stop live timer
            if (timerInterval) {
                clearInterval(timerInterval);
                timerInterval = null;
            }
            // Hide loading state
            submitBtn.disabled = false;
            btnText.style.display = 'block';
            loader.style.display = 'none';
            if (liveTimer) {
                liveTimer.style.display = 'none';
            }
        });
    });

    function renderResults() {
        resultsSection.style.display = 'block';
        resultStoreName.textContent = currentData.storeName;

        if (currentData.elapsedTime !== null) {
            reconciliationTime.textContent = `İşlem ${currentData.elapsedTime} saniyede başarıyla tamamlandı.`;
        } else {
            reconciliationTime.textContent = 'İşlem tamamlandı.';
        }

        const mCount = currentData.matched.length;
        const misCount = currentData.missingInStore.length;
        const exCount = currentData.extraInStore.length;
        const susCount = currentData.suspectedMatches.length;

        statMatchedVal.textContent = mCount;
        statMissingVal.textContent = misCount;
        statExtraVal.textContent = exCount;
        statSuspectedVal.textContent = susCount;

        countAll.textContent = mCount + misCount + exCount + susCount;
        countMatched.textContent = mCount;
        countMissing.textContent = misCount;
        countExtra.textContent = exCount;
        countSuspected.textContent = susCount;

        // Render PDF Mismatches Warning (Disabled as per user request)
        const mismatchContainer = document.getElementById('mismatchContainer');
        mismatchContainer.style.display = 'none';
        mismatchContainer.innerHTML = '';

        buildTable();
    }

    function buildTable() {
        tableBody.innerHTML = '';
        const searchVal = tableSearch.value.toLowerCase().trim();
        let matchFound = false;

        const getOcrAlert = (barcode) => {
            const originalWord = currentData.pdfOriginalWords[barcode];
            if (originalWord && originalWord !== barcode) {
                return `<br><span class="ocr-warning-box" title="PDF font kodlaması hatalı basılmış. PDF'te aramak için bu kodu kopyalayın.">
                    PDF Arama Kodu: <code class="copyable-code" title="Kopyalamak için tıklayın" onclick="navigator.clipboard.writeText('${escapeHtml(originalWord)}'); alert('PDF arama kodu kopyalandı: ${escapeHtml(originalWord)}')">${escapeHtml(originalWord)}</code> 📋
                </span>`;
            }
            return '';
        };

        const cleanStoreName = (name) => {
            if (!name || name === 'Bilinmeyen Mağaza' || name.trim() === '') {
                return 'Eşleşme Sonucu';
            }
            const commaCount = (name.match(/,/g) || []).length;
            const quoteCount = (name.match(/["']/g) || []).length;
            const dotDotDot = name.includes('...');
            if (commaCount >= 2 || quoteCount >= 1 || dotDotDot || name.includes('rağaza') || name.includes('Mağa.a') || name.includes('Mağaza,')) {
                return 'Eşleşme Sonucu';
            }
            return name;
        };

        allRows = [];

        // Missing Items (Kırmızı)
        currentData.missingInStore.forEach(barcode => {
            const rawStoreName = currentData.barcodeStores[barcode];
            const storeName = cleanStoreName(rawStoreName);
            allRows.push({
                type: 'missing',
                barcode: barcode,
                storeBarcode: '-',
                storeName: storeName,
                html: `<tr class="row-missing" data-type="missing">
                    <td class="font-semibold">${escapeHtml(barcode)}</td>
                    <td class="text-muted">-</td>
                    <td class="text-secondary">${escapeHtml(storeName)}</td>
                    <td><span class="badge badge-missing">Eksik</span></td>
                    <td class="text-muted">Terminalde okutulmuş ancak Mağaza PDF'inde bulunamadı.</td>
                </tr>`
            });
        });

        // Extra Items (Sarı/Turuncu)
        currentData.extraInStore.forEach(barcode => {
            const rawStoreName = currentData.barcodeStores[barcode];
            const storeName = cleanStoreName(rawStoreName);
            allRows.push({
                type: 'extra',
                barcode: '-',
                storeBarcode: barcode,
                storeName: storeName,
                html: `<tr class="row-extra" data-type="extra">
                    <td class="text-muted">-</td>
                    <td class="font-semibold">
                        ${escapeHtml(barcode)}
                        ${getOcrAlert(barcode)}
                    </td>
                    <td class="text-secondary">${escapeHtml(storeName)}</td>
                    <td><span class="badge badge-extra">Fazla</span></td>
                    <td class="text-muted">Mağaza PDF'inde mevcut ancak Terminalde okutulmamış.</td>
                </tr>`
            });
        });

        // Suspected Items (Sarı/Amber)
        currentData.suspectedMatches.forEach((suspect, idx) => {
            const barcode = suspect.terminal_barcode;
            const storeBarcode = suspect.store_barcode;
            const distance = suspect.distance;
            const rawStoreName = currentData.barcodeStores[barcode] || currentData.barcodeStores[storeBarcode];
            const storeName = cleanStoreName(rawStoreName);
            allRows.push({
                type: 'suspected',
                barcode: barcode,
                storeBarcode: storeBarcode,
                storeName: storeName,
                html: `<tr class="row-suspected" data-type="suspected" id="suspected-row-${idx}">
                    <td class="font-semibold">${escapeHtml(barcode)}</td>
                    <td class="font-semibold">
                        ${escapeHtml(storeBarcode)}
                        <div class="suspected-detail">
                            <span class="levenshtein-badge">🔍 ${distance} karakter fark</span>
                        </div>
                    </td>
                    <td class="text-secondary">${escapeHtml(storeName)}</td>
                    <td><span class="badge badge-suspected">Şüpheli</span></td>
                    <td>
                        <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                            <span class="text-muted" style="font-size:0.85rem;">Yakın eşleşme bulundu</span>
                            <button type="button" class="btn-approve" onclick="approveSuspected(${idx}, this)">
                                ✅ Manuel Onayla
                            </button>
                        </div>
                    </td>
                </tr>`
            });
        });

        // Matched Items (Yeşil)
        currentData.matched.forEach(barcode => {
            const rawStoreName = currentData.barcodeStores[barcode];
            const storeName = cleanStoreName(rawStoreName);
            allRows.push({
                type: 'matched',
                barcode: barcode,
                storeBarcode: barcode,
                storeName: storeName,
                html: `<tr class="row-matched" data-type="matched">
                    <td class="font-semibold">${escapeHtml(barcode)}</td>
                    <td class="font-semibold">
                        ${escapeHtml(barcode)}
                        ${getOcrAlert(barcode)}
                    </td>
                    <td class="text-secondary">${escapeHtml(storeName)}</td>
                    <td><span class="badge badge-matched">Eşleşti</span></td>
                    <td class="text-muted">Her iki listede de başarıyla eşleşti.</td>
                </tr>`
            });
        });

        // Mağaza adına göre alfabetik (A-Z) sırala
        allRows.sort((a, b) => {
            return a.storeName.localeCompare(b.storeName, 'tr', { sensitivity: 'base' });
        });

        let rowsHtml = '';
        allRows.forEach(row => {
            if (activeFilter !== 'all' && row.type !== activeFilter) {
                return;
            }

            const barcodeVal = row.barcode.toLowerCase();
            const storeBarcodeVal = row.storeBarcode.toLowerCase();
            const storeNameVal = row.storeName.toLowerCase();
            
            const barcodeMatch = barcodeVal.includes(searchVal) || storeBarcodeVal.includes(searchVal);
            const storeNameMatch = storeNameVal.includes(searchVal);

            if (searchVal === '' || barcodeMatch || storeNameMatch) {
                rowsHtml += row.html;
                matchFound = true;
            }
        });

        tableBody.innerHTML = rowsHtml;
        noDataMsg.style.display = matchFound ? 'none' : 'block';
    }

    // Filter Tabs
    filterButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            filterButtons.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeFilter = btn.dataset.filter;
            buildTable();
        });
    });

    // Search input event
    tableSearch.addEventListener('input', () => {
        buildTable();
    });

    // Excel Download (Dinamik Filtreli ve Barkod Tipli)
    downloadExcelBtn.addEventListener('click', () => {
        const searchVal = tableSearch.value.toLowerCase().trim();
        const filteredData = [];

        allRows.forEach(row => {
            if (activeFilter !== 'all' && row.type !== activeFilter) {
                return;
            }

            const barcodeVal = row.barcode.toLowerCase();
            const storeBarcodeVal = row.storeBarcode.toLowerCase();
            const storeNameVal = row.storeName.toLowerCase();
            
            const barcodeMatch = barcodeVal.includes(searchVal) || storeBarcodeVal.includes(searchVal);
            const storeNameMatch = storeNameVal.includes(searchVal);

            if (searchVal === '' || barcodeMatch || storeNameMatch) {
                let statusText = '';
                let descText = '';
                
                if (row.type === 'missing') {
                    statusText = 'Eksik';
                    descText = 'Terminalde okutulmuş ancak Mağaza PDF\'inde bulunamadı.';
                } else if (row.type === 'extra') {
                    statusText = 'Fazla';
                    descText = 'Mağaza PDF\'inde mevcut ancak Terminalde okutulmamış.';
                } else if (row.type === 'suspected') {
                    statusText = 'Şüpheli';
                    descText = 'Yakın eşleşme bulundu.';
                } else if (row.type === 'matched') {
                    statusText = 'Eşleşti';
                    descText = 'Her iki listede de başarıyla eşleşti.';
                }

                filteredData.push({
                    terminal_barcode: row.barcode,
                    pdf_barcode: row.storeBarcode,
                    store_name: row.storeName,
                    status: statusText,
                    description: descText
                });
            }
        });

        if (filteredData.length === 0) {
            alert('İndirilecek veri bulunamadı.');
            return;
        }

        // Sunucuya POST etmek için gizli bir form oluştur
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'index.php?action=export-excel';
        form.style.display = 'none';

        const dataInput = document.createElement('input');
        dataInput.type = 'hidden';
        dataInput.name = 'data';
        dataInput.value = JSON.stringify(filteredData);
        form.appendChild(dataInput);

        const nameInput = document.createElement('input');
        nameInput.type = 'hidden';
        nameInput.name = 'store_name';
        nameInput.value = currentData.storeName;
        form.appendChild(nameInput);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    });

    // Load past report details
    document.querySelectorAll('.btn-view-report').forEach(btn => {
        btn.addEventListener('click', () => {
            const reportId = btn.dataset.id;
            fetch(`index.php?action=get-report&id=${reportId}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success) {
                        currentData.storeName = data.store_name;
                        currentData.matched = data.result.matched;
                        currentData.missingInStore = data.result.missingInStore;
                        currentData.extraInStore = data.result.extraInStore;
                        currentData.pdfOriginalWords = {}; // clear for past db reports
                        currentData.pdfMismatches = []; // clear for past db reports
                        currentData.suspectedMatches = data.result.suspectedMatches || [];
                        
                        // terminalBarcodes fallback
                        if (data.result.terminalBarcodes) {
                            currentData.terminalBarcodes = data.result.terminalBarcodes;
                        } else {
                            const termSuspects = (data.result.suspectedMatches || []).map(s => s.terminal_barcode);
                            currentData.terminalBarcodes = [...new Set([...(data.result.matched || []), ...(data.result.missingInStore || []), ...termSuspects])];
                        }
                        
                        // storeBarcodes fallback
                        if (data.result.storeBarcodes) {
                            currentData.storeBarcodes = data.result.storeBarcodes;
                        } else {
                            const storeSuspects = (data.result.suspectedMatches || []).map(s => s.store_barcode);
                            currentData.storeBarcodes = [...new Set([...(data.result.matched || []), ...(data.result.extraInStore || []), ...storeSuspects])];
                        }

                        renderResults();
                        resultsSection.scrollIntoView({ behavior: 'smooth' });
                    } else {
                        alert('Rapor yüklenemedi: ' + data.message);
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Rapor yüklenirken hata oluştu.');
                });
        });
    });

    function escapeHtml(text) {
        return text
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

    // Global approve function for suspected matches
    window.approveSuspected = function(idx, btn) {
        const suspect = currentData.suspectedMatches[idx];
        if (!suspect) return;

        // Şüpheli listesinden çıkar
        currentData.suspectedMatches.splice(idx, 1);

        // Eşleşmiş listeye ekle
        currentData.matched.push(suspect.terminal_barcode);

        // Eksiklerden de temizle (eğer varsa)
        const missingIdx = currentData.missingInStore.indexOf(suspect.terminal_barcode);
        if (missingIdx > -1) {
            currentData.missingInStore.splice(missingIdx, 1);
        }

        // Fazlalardan da temizle (eğer varsa)
        const extraIdx = currentData.extraInStore.indexOf(suspect.store_barcode);
        if (extraIdx > -1) {
            currentData.extraInStore.splice(extraIdx, 1);
        }

        // Tabloyu ve istatistikleri yeniden render et
        renderResults();
    };

    // Show Raw Data in a new window/tab
    showRawDataBtn.addEventListener('click', () => {
        if (!currentData.terminalBarcodes || currentData.terminalBarcodes.length === 0) {
            alert('Gösterilecek veri bulunamadı.');
            return;
        }

        // Sunucu tarafından zaten numerik küçükten büyüğe sıralı olarak gönderilir
        const sortedTerminal = currentData.terminalBarcodes;
        const sortedStore = currentData.storeBarcodes;

        const newWin = window.open('', '_blank');
        if (!newWin) {
            alert('Yeni pencere açılması pop-up engelleyici tarafından engellendi. Lütfen izin verin.');
            return;
        }

        // HTML içeriğini oluştur
        let excelRows = sortedTerminal.map(b => `<tr><td>${escapeHtml(b)}</td></tr>`).join('');
        let pdfRows = sortedStore.map(b => `<tr><td>${escapeHtml(b)}</td></tr>`).join('');

        const html = `<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ham Barkod Listesi - ${escapeHtml(currentData.storeName)}</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-color: #F8FAFC;
            --text-primary: #1E293B;
            --text-secondary: #475569;
            --border-color: #E2E8F0;
            --primary: #7C3AED;
            --primary-hover: #6D28D9;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: var(--bg-color);
            color: var(--text-primary);
            padding: 2rem;
            line-height: 1.5;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            border-bottom: 2px solid var(--border-color);
            padding-bottom: 1rem;
        }
        .title h1 { font-size: 1.5rem; font-weight: 700; color: #0F172A; }
        .title p { font-size: 0.9rem; color: var(--text-secondary); margin-top: 0.25rem; }
        .btn-print {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.6rem 1.2rem;
            font-size: 0.9rem;
            font-weight: 600;
            border-radius: 8px;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .btn-print:hover { background-color: var(--primary-hover); }
        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
        }
        .col {
            background: white;
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            max-height: 80vh;
            display: flex;
            flex-direction: column;
        }
        .col-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }
        .col-title { font-size: 1.1rem; font-weight: 700; color: #0F172A; }
        .col-count {
            background: #F1F5F9;
            color: var(--text-secondary);
            padding: 0.2rem 0.6rem;
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: 9999px;
        }
        .table-container {
            overflow-y: auto;
            flex: 1;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        td {
            padding: 0.6rem 0.5rem;
            border-bottom: 1px solid #F1F5F9;
            font-family: monospace;
            font-size: 0.95rem;
            color: #334155;
            letter-spacing: 0.5px;
        }
        tr:last-child td { border-bottom: none; }
        @media print {
            body { padding: 0; background: white; }
            .btn-print { display: none; }
            .col { box-shadow: none; border: none; padding: 0; max-height: none; }
            .grid { gap: 1rem; }
            .table-container { overflow-y: visible; }
            .header { margin-bottom: 1rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="title">
            <h1>Ham Barkod Listesi</h1>
            <p>Mağaza/Araç: <strong>${escapeHtml(currentData.storeName)}</strong> | Karşılaştırma yapılmamış, ham sıralı listeler.</p>
        </div>
        <button class="btn-print" onclick="window.print()">Yazdır / PDF Kaydet</button>
    </div>
    <div class="grid">
        <div class="col">
            <div class="col-header">
                <span class="col-title">📊 El Terminali (Excel)</span>
                <span class="col-count">${sortedTerminal.length} Barkod</span>
            </div>
            <div class="table-container">
                <table>
                    <tbody>
                        ${excelRows || '<tr><td style="color:var(--text-muted); font-style:italic;">Barkod bulunamadı.</td></tr>'}
                    </tbody>
                </table>
            </div>
        </div>
        <div class="col">
            <div class="col-header">
                <span class="col-title">📕 Mağaza PDF</span>
                <span class="col-count">${sortedStore.length} Barkod</span>
            </div>
            <div class="table-container">
                <table>
                    <tbody>
                        ${pdfRows || '<tr><td style="color:var(--text-muted); font-style:italic;">Barkod bulunamadı.</td></tr>'}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>`;

        newWin.document.write(html);
        newWin.document.close();
    });
});
