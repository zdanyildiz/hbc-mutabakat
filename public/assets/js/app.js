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
                showFile(input.files[0].name);
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
                input.files = dt.files;
                showFile(dt.files[0].name);
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

        function showFile(name) {
            fileNameSpan.textContent = name;
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
    const downloadCsvBtn = document.getElementById('downloadCsvBtn');

    let currentData = {
        storeName: '',
        matched: [],
        missingInStore: [],
        extraInStore: [],
        barcodeStores: {}
    };

    let activeFilter = 'all';

    form.addEventListener('submit', (e) => {
        e.preventDefault();

        // Show loading state
        submitBtn.disabled = true;
        btnText.style.display = 'none';
        loader.style.display = 'block';

        const formData = new FormData(form);

        fetch('index.php?action=reconcile', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Save data locally for rendering and exporting
                currentData.storeName = data.store_name;
                currentData.matched = data.result.matched;
                currentData.missingInStore = data.result.missingInStore;
                currentData.extraInStore = data.result.extraInStore;
                currentData.barcodeStores = data.barcode_stores || {};

                renderResults();
                resultsSection.scrollIntoView({ behavior: 'smooth' });
                
                // Rapor başarıyla tamamlandığı için formu temizleyip dosyaları kaldırabiliriz
                // Ancak verileri ekranda gösterdiğimiz için kullanıcı görebilir.
            } else {
                alert('Hata: ' + data.message);
            }
        })
        .catch(err => {
            console.error(err);
            alert('İşlem sırasında beklenmedik bir hata oluştu.');
        })
        .finally(() => {
            // Hide loading state
            submitBtn.disabled = false;
            btnText.style.display = 'block';
            loader.style.display = 'none';
        });
    });

    function renderResults() {
        resultsSection.style.display = 'block';
        resultStoreName.textContent = currentData.storeName;

        const mCount = currentData.matched.length;
        const misCount = currentData.missingInStore.length;
        const exCount = currentData.extraInStore.length;

        statMatchedVal.textContent = mCount;
        statMissingVal.textContent = misCount;
        statExtraVal.textContent = exCount;

        countAll.textContent = mCount + misCount + exCount;
        countMatched.textContent = mCount;
        countMissing.textContent = misCount;
        countExtra.textContent = exCount;

        buildTable();
    }

    function buildTable() {
        tableBody.innerHTML = '';
        const searchVal = tableSearch.value.toLowerCase().trim();
        let rowsHtml = '';
        let matchFound = false;

        // Missing Items (Kırmızı)
        if (activeFilter === 'all' || activeFilter === 'missing') {
            currentData.missingInStore.forEach(barcode => {
                if (searchVal === '' || barcode.toLowerCase().includes(searchVal)) {
                    const storeName = currentData.barcodeStores[barcode] || 'Bilinmeyen Mağaza';
                    rowsHtml += `<tr class="row-missing" data-type="missing">
                        <td class="font-semibold">${escapeHtml(barcode)}</td>
                        <td class="text-secondary">${escapeHtml(storeName)}</td>
                        <td><span class="badge badge-missing">Eksik</span></td>
                        <td class="text-muted">Terminalde okutulmuş ancak Mağaza PDF'inde bulunamadı.</td>
                    </tr>`;
                    matchFound = true;
                }
            });
        }

        // Extra Items (Sarı/Turuncu)
        if (activeFilter === 'all' || activeFilter === 'extra') {
            currentData.extraInStore.forEach(barcode => {
                if (searchVal === '' || barcode.toLowerCase().includes(searchVal)) {
                    const storeName = currentData.barcodeStores[barcode] || 'Bilinmeyen Mağaza';
                    rowsHtml += `<tr class="row-extra" data-type="extra">
                        <td class="font-semibold">${escapeHtml(barcode)}</td>
                        <td class="text-secondary">${escapeHtml(storeName)}</td>
                        <td><span class="badge badge-extra">Fazla</span></td>
                        <td class="text-muted">Mağaza PDF'inde mevcut ancak Terminalde okutulmamış.</td>
                    </tr>`;
                    matchFound = true;
                }
            });
        }

        // Matched Items (Yeşil)
        if (activeFilter === 'all' || activeFilter === 'matched') {
            currentData.matched.forEach(barcode => {
                if (searchVal === '' || barcode.toLowerCase().includes(searchVal)) {
                    const storeName = currentData.barcodeStores[barcode] || 'Bilinmeyen Mağaza';
                    rowsHtml += `<tr class="row-matched" data-type="matched">
                        <td class="font-semibold">${escapeHtml(barcode)}</td>
                        <td class="text-secondary">${escapeHtml(storeName)}</td>
                        <td><span class="badge badge-matched">Eşleşti</span></td>
                        <td class="text-muted">Her iki listede de başarıyla eşleşti.</td>
                    </tr>`;
                    matchFound = true;
                }
            });
        }

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

    // CSV Download (Client-Side)
    downloadCsvBtn.addEventListener('click', () => {
        let csvContent = "\ufeffBarkod / Takip No,Durum,Aciklama\n";
        
        currentData.missingInStore.forEach(barcode => {
            csvContent += `"${barcode}","Eksik","Terminalde var, PDF'te yok"\n`;
        });
        currentData.extraInStore.forEach(barcode => {
            csvContent += `"${barcode}","Fazla","PDF'te var, Terminalde yok"\n`;
        });
        currentData.matched.forEach(barcode => {
            csvContent += `"${barcode}","Eşleşti","Sorunsuz eşleşti"\n`;
        });

        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement("a");
        link.setAttribute("href", url);
        link.setAttribute("download", `mutabakat_raporu_${currentData.storeName.replace(/[^a-zA-Z0-9]/g, '_')}.csv`);
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
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
});
