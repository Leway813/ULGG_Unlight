document.addEventListener("DOMContentLoaded", () => {

    const body = document.body;
    const navToggle = document.querySelector(".nav-toggle");
    const overlay = document.querySelector(".un-overlay");

    // 如果按鈕不存在就不要報錯
    if (navToggle) {
        navToggle.addEventListener("click", () => {
            body.classList.toggle("sidebar-open");
        });
    }

    if (overlay) {
        overlay.addEventListener("click", () => {
            body.classList.remove("sidebar-open");
        });
    }

    // ESC
    document.addEventListener("keydown", (e) => {
        if (e.key === "Escape") {
            body.classList.remove("sidebar-open");
        }
    });

});
