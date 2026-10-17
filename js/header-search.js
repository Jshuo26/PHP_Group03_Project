document.addEventListener('DOMContentLoaded', function () {
    const searchForm = document.getElementById('headerSearchForm');
    const searchType = document.getElementById('headerSearchType');
    const searchInput = document.getElementById('headerSearchInput');

    if (!searchForm || !searchType || !searchInput) {
        return;
    }

    function updateSearchForm() {
        if (searchType.value === 'authors') {
            searchForm.action = '/authors.php';
            searchInput.placeholder = 'Search authors';            searchInput.setAttribute('aria-label', 'Search authors');
        } else {
            searchForm.action = '/books.php';
            searchInput.placeholder = 'Search books or authors';            searchInput.setAttribute('aria-label', 'Search books');
        }
    }

    searchType.addEventListener('change', updateSearchForm);

    updateSearchForm();
});