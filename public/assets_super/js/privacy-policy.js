(function() {
    var nav = document.querySelector('.navbar-landing');
    window.addEventListener('scroll', function() {
        nav.classList.toggle('scrolled', window.scrollY > 20);
    });
})();
