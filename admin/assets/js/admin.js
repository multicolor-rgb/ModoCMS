document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('admin-sidebar');
    const collapseTrigger = document.getElementById('collapse-trigger');

    if (!sidebar || !collapseTrigger) return;

    if (localStorage.getItem('clean_cms_sidebar_collapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }

    collapseTrigger.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('clean_cms_sidebar_collapsed', sidebar.classList.contains('collapsed'));
    });
});
