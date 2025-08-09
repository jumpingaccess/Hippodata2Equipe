// app/javascript/packs/import.js

document.addEventListener("DOMContentLoaded", function() {
    const debugMode = false; // Définissez ceci à true pour activer les logs de débogage

    let currentEventData = null;
    let currentSelections = [];
    let importOptions = {
        classes: false,
        startlists: false,
        results: false
    };

    function debugLog(...args) {
        if (debugMode) {
            console.log(...args);
        }
    }

    // Recherche de l'event
    document.getElementById('search-form').addEventListener('submit', function(e) {
        e.preventDefault();
        const showId = document.getElementById('show-id').value;
        const button = document.getElementById('search-button');
        const alertDiv = document.getElementById('alertMessage');

        alertDiv.style.display = 'none';
        button.disabled = true;
        button.textContent = 'Searching...';

        fetch('/import/fetch_event_info', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({ show_id: showId })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                currentEventData = data;
                displayEventInfo(data.event, data.classes);
                document.getElementById('search-step').style.display = 'none';
                document.getElementById('selection-step').style.display = 'block';
            } else {
                alertDiv.classList.remove('alert-success');
                alertDiv.classList.add('alert', 'alert-danger');
                alertDiv.textContent = 'Error: ' + data.error;
                alertDiv.style.display = 'block';
            }
        })
        .catch(error => {
            alertDiv.classList.remove('alert-success');
            alertDiv.classList.add('alert', 'alert-danger');
            alertDiv.textContent = 'Request failed: ' + error;
            alertDiv.style.display = 'block';
        })
        .finally(() => {
            button.disabled = false;
            button.textContent = 'Search Event';
        });
    });

    // Afficher les informations de l'événement et les classes
    function displayEventInfo(event, classes) {
        document.getElementById('event-info').innerHTML = `
            <h4>${event.name}</h4>
            <p><strong>Event ID:</strong> ${event.id}</p>
            <p><strong>Venue:</strong> ${event.venue}</p>
        `;

        const tbody = document.getElementById('classes-table-body');
        tbody.innerHTML = '';
        classes.forEach((cls, index) => {
            const row = document.createElement('tr');
            row.innerHTML = `
                <td>${cls.nr} ${cls.name}</td>
                <td>${cls.date}</td>
                <td><input type="checkbox" class="class-import" data-id="${cls.id}"></td>
                <td><input type="checkbox" class="startlist-import" data-id="${cls.id}"></td>
                <td><input type="checkbox" class="result-import" data-id="${cls.id}"></td>
            `;
            tbody.appendChild(row);
        });
    }

    // Action "Import Selected"
    document.getElementById('import-selected').addEventListener('click', function() {
        const selections = [];
        
        document.querySelectorAll('#classes-table-body tr').forEach((row, index) => {
            const classData = currentEventData.classes[index];
            const classCheckbox = row.querySelector('.class-import');
            const startlistCheckbox = row.querySelector('.startlist-import');
            const resultCheckbox = row.querySelector('.result-import');
            
            const selection = {
                class_id: classData.id,
                class_nr: classData.nr,
                class_name: classData.name,
                import_class: classCheckbox && classCheckbox.checked,
                import_startlist: startlistCheckbox && startlistCheckbox.checked,
                import_results: resultCheckbox && resultCheckbox.checked
            };
            
            if (selection.import_class || selection.import_startlist || selection.import_results) {
                selections.push(selection);
            }
        });
        
        if (selections.length === 0) {
            alert('Please select at least one import option');
            return;
        }

        currentSelections = selections;
        startImport();
    });

    // Démarrer l'import
    function startImport() {
        document.getElementById('selection-step').style.display = 'none';
        document.getElementById('results-step').style.display = 'block';
        document.getElementById('importProgress').innerHTML = '<div class="progress-section"><p>Starting import process...</p></div>';
        
        importOptions.classes = currentSelections.some(s => s.import_class);
        importOptions.startlists = currentSelections.some(s => s.import_startlist);
        importOptions.results = currentSelections.some(s => s.import_results);

        fetch('/import/import_selected', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                show_id: document.getElementById('show-id').value,
                selections: JSON.stringify(currentSelections)
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                displayImportResults(data);
            } else {
                document.getElementById('importProgress').innerHTML = `<p class="alert alert-danger">Error: ${data.error}</p>`;
            }
        })
        .catch(error => {
            document.getElementById('importProgress').innerHTML = `<p class="alert alert-danger">Request failed: ${error}</p>`;
        });
    }

    // Afficher les résultats de l'import
    function displayImportResults(data) {
        let html = '<div class="progress-section">';

        if (data.results.classes && data.results.classes.length > 0) {
            html += '<h5>Classes Import Results:</h5>';
            data.results.classes.forEach(cls => {
                const statusClass = cls.status === 'success' ? 'success' : 'failed';
                html += `<div class="progress-item ${statusClass}">${cls.name} - <strong>${cls.status}</strong></div>`;
            });
        }

        html += '</div>';
        document.getElementById('importProgress').innerHTML = html;
    }

    // Retour à la recherche
    document.getElementById('back-button').addEventListener('click', function() {
        document.getElementById('selection-step').style.display = 'none';
        document.getElementById('search-step').style.display = 'block';
    });

    // Gestion du bouton "New Import"
    document.getElementById('new-import-button').addEventListener('click', function() {
        document.getElementById('results-step').style.display = 'none';
        document.getElementById('search-step').style.display = 'block';
        document.getElementById('show-id').value = '';
        currentEventData = null;
        currentSelections = [];
        importOptions = {
            classes: false,
            startlists: false,
            results: false
        };
    });
});
