(function () {
    "use strict";

    const root = document.documentElement;
    const meta = (name) => {
        const el = document.querySelector('meta[name="' + name + '"]');
        return el ? el.getAttribute("content") : "";
    };

    const themeSwitchEnabled = meta("theme-switch") !== "0";
    const defaultTheme = meta("default-theme") || "light";

    function applyTheme(theme, persist) {
        const value = theme === "dark" ? "dark" : "light";
        root.setAttribute("data-theme", value);
        root.setAttribute("data-bs-theme", value);
        if (persist && themeSwitchEnabled) {
            try {
                localStorage.setItem("fch-theme", value);
            } catch (e) {}
        }
        document.querySelectorAll("[data-theme-icon]").forEach((icon) => {
            icon.setAttribute("data-lucide", value === "dark" ? "sun" : "moon");
        });
        if (window.lucide) {
            window.lucide.createIcons();
        }
    }

    if (themeSwitchEnabled) {
        let saved = null;
        try {
            saved = localStorage.getItem("fch-theme");
        } catch (e) {}
        applyTheme(saved || defaultTheme, false);
    } else {
        applyTheme(defaultTheme, false);
    }

    document.addEventListener("click", function (event) {
        const toggle = event.target.closest("#themeToggle");
        if (toggle) {
            event.preventDefault();
            const next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
            applyTheme(next, true);
        }
    });

    const appUrl = (meta("app-url") || "").replace(/\/$/, "");
    const csrfToken = meta("csrf-token");

    function toast(message, type) {
        let stack = document.querySelector(".toast-stack");
        if (!stack) {
            stack = document.createElement("div");
            stack.className = "toast-stack";
            document.body.appendChild(stack);
        }
        const item = document.createElement("div");
        item.className = "toast-item " + (type || "info");
        item.textContent = message;
        stack.appendChild(item);
        window.setTimeout(function () {
            item.style.opacity = "0";
            item.style.transform = "translateY(8px)";
            item.style.transition = "opacity .2s ease, transform .2s ease";
            window.setTimeout(function () {
                item.remove();
            }, 220);
        }, 5200);
    }

    async function postForm(path, data) {
        const body = new URLSearchParams();
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });
        body.append("_token", csrfToken);
        const response = await fetch(appUrl + "/" + path.replace(/^\//, ""), {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                "X-CSRF-Token": csrfToken,
                "X-Requested-With": "XMLHttpRequest",
            },
            body: body.toString(),
        });
        let payload = null;
        try {
            payload = await response.json();
        } catch (e) {
            throw new Error("Unexpected server response. Please try again.");
        }
        if (!payload || payload.ok !== true) {
            throw new Error((payload && payload.error) || "Request failed. Please try again.");
        }
        return payload;
    }

    document.addEventListener("click", async function (event) {
        const button = event.target.closest("[data-unlock-course]");
        if (!button) {
            return;
        }
        event.preventDefault();
        if (button.dataset.busy === "1") {
            return;
        }
        button.dataset.busy = "1";
        const original = button.innerHTML;
        const courseId = button.getAttribute("data-unlock-course");
        button.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Unlocking...';
        button.disabled = true;

        try {
            const result = await postForm("api/courses/unlock.php", { course_id: courseId });
            if (result.status === "unlocked" && result.access_url) {
                toast("Course unlocked. Redirecting...", "success");
                window.location.href = result.access_url;
                return;
            }
            if (result.status === "requires_payment") {
                toast("Free unlocks exhausted. Starting secure checkout...", "info");
                const payment = await postForm("api/payments/initialize.php", { course_id: courseId });
                if (payment.authorization_url) {
                    window.location.href = payment.authorization_url;
                    return;
                }
                throw new Error("Unable to start payment. Please try again.");
            }
            throw new Error("Unable to unlock this course right now.");
        } catch (error) {
            toast(error.message || "Something went wrong.", "error");
        } finally {
            button.dataset.busy = "0";
            button.disabled = false;
            button.innerHTML = original;
            if (window.lucide) {
                window.lucide.createIcons();
            }
        }
    });

    document.addEventListener("click", function (event) {
        const toggle = event.target.closest("[data-toggle-password]");
        if (!toggle) {
            return;
        }
        event.preventDefault();
        const input = document.getElementById(toggle.getAttribute("data-toggle-password"));
        if (!input) {
            return;
        }
        input.type = input.type === "password" ? "text" : "password";
        toggle.setAttribute("data-lucide", input.type === "password" ? "eye" : "eye-off");
        if (window.lucide) {
            window.lucide.createIcons();
        }
    });

    document.addEventListener("submit", function (event) {
        const form = event.target;
        const message = form.getAttribute("data-confirm");
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }
        const submitButton = form.querySelector('[type="submit"]');
        if (submitButton && !submitButton.dataset.noLoading) {
            submitButton.dataset.originalText = submitButton.innerHTML;
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Please wait...';
        }
    });

    const adminToggle = document.getElementById("adminMenuToggle");
    if (adminToggle) {
        adminToggle.addEventListener("click", function () {
            const sidebar = document.querySelector(".admin-sidebar");
            if (sidebar) {
                sidebar.classList.toggle("open");
            }
        });
    }

    document.addEventListener("DOMContentLoaded", function () {
        if (window.lucide) {
            window.lucide.createIcons();
        }
        document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(function (element) {
            if (window.bootstrap) {
                new window.bootstrap.Tooltip(element);
            }
        });
        const alerts = document.querySelectorAll("[data-auto-dismiss]");
        alerts.forEach(function (alert) {
            window.setTimeout(function () {
                alert.style.opacity = "0";
                alert.style.transition = "opacity .3s ease";
                window.setTimeout(function () {
                    alert.remove();
                }, 320);
            }, 6000);
        });
    });
})();
