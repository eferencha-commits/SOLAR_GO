// ==============================
// MOBILE NAVIGATION MENU
// ==============================

const menuToggle = document.getElementById("menuToggle");
const navLinks = document.getElementById("navLinks");

if (menuToggle && navLinks) {
    menuToggle.addEventListener("click", function () {

        navLinks.classList.toggle("show");

        const isExpanded = navLinks.classList.contains("show");

        menuToggle.setAttribute("aria-expanded", isExpanded);

        menuToggle.textContent = isExpanded ? "✕" : "☰";
    });
}


// ==============================
// AUTOMATIC COPYRIGHT YEAR
// ==============================

const yearElement = document.getElementById("year");

if (yearElement) {
    yearElement.textContent = new Date().getFullYear();
}


document.querySelectorAll(".thumbnail-button").forEach(function (button) {
    button.addEventListener("click", function () {
        const mainImage = document.getElementById("mainProductImage");

        if (!mainImage) {
            return;
        }

        mainImage.src = this.dataset.image;

        document.querySelectorAll(".thumbnail-button").forEach(function (item) {
            item.classList.remove("active");
            item.setAttribute("aria-pressed", "false");
        });

        this.classList.add("active");
        this.setAttribute("aria-pressed", "true");
    });
});
