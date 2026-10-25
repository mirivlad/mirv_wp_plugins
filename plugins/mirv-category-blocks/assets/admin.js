(function () {
  function ready(fn) {
    if (document.readyState !== 'loading') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  }

  ready(function () {
    var addButton = document.getElementById('mcb-add-section');
    var tbody = document.getElementById('mcb-sections-body');
    var template = document.getElementById('mcb-section-row-template');

    if (!addButton || !tbody || !template) {
      return;
    }

    addButton.addEventListener('click', function () {
      var index = Date.now().toString();
      var html = template.innerHTML.replace(/__INDEX__/g, index);
      var wrapper = document.createElement('tbody');
      wrapper.innerHTML = html.trim();
      var row = wrapper.querySelector('tr');
      if (row) {
        tbody.appendChild(row);
      }
    });

    tbody.addEventListener('click', function (event) {
      var target = event.target;
      if (target && target.classList.contains('mcb-remove-section')) {
        event.preventDefault();
        var row = target.closest('tr');
        if (row) {
          row.remove();
        }
      }
    });
  });
})();
