
(function () {
  var key = "scroll-restore-" + window.location.pathname;


  document.addEventListener("submit", function (event) {
    try {
      sessionStorage.setItem(key, String(window.scrollY));
    } catch (e) {}
  }, true);

  document.querySelectorAll("[data-scroll-stay]").forEach(function (el) {
    el.addEventListener("click", function () {
      try {
        sessionStorage.setItem(key, String(window.scrollY));
      } catch (e) {}
    });
  });


  if (sessionStorage.getItem(key) !== null) {
    var y = parseInt(sessionStorage.getItem(key), 10) || 0;
    sessionStorage.removeItem(key);
    if (y > 0) {
      window.scrollTo(0, y);

      window.addEventListener("load", function () {
        window.scrollTo(0, y);
      });
    }
  }
})();

document.addEventListener("DOMContentLoaded", function () {

  document.querySelectorAll("[data-confirm]").forEach(function (el) {
    el.addEventListener("click", function (event) {
      if (!window.confirm(el.getAttribute("data-confirm"))) {
        event.preventDefault();
      }
    });
  });


  var params = new URLSearchParams(window.location.search);
  var hasFilter = ["search", "category", "sort"].some(function (key) {
    var val = params.get(key);
    return val !== null && val !== "" && val !== "all";
  });
  if (hasFilter) {
    var results = document.querySelector(".event-grid, .message.empty, #events");
    if (results) {
      window.setTimeout(function () {
        results.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 100);
    }
  }

  document
    .querySelectorAll(".message.success, .message.info")
    .forEach(function (msg) {
      setTimeout(function () {
        msg.style.transition = "opacity 0.6s ease";
        msg.style.opacity = "0";
        setTimeout(function () {
          msg.remove();
        }, 700);
      }, 5000);
    });

  var STATUS_TEXT = {
    upcoming: "⏱ Upcoming",
    ongoing: "● Ongoing",
    finished: "✓ Finished",
  };

  function computeStatus(startTs, endTs, nowTs) {
    if (nowTs >= startTs && nowTs <= endTs) return "ongoing";
    if (nowTs > endTs) return "finished";
    return "upcoming";
  }

  function refreshStatuses() {

    var nowSeconds = Math.floor(Date.now() / 1000);

    document.querySelectorAll(".event-status-badge[data-start]").forEach(function (badge) {
      var start = parseInt(badge.getAttribute("data-start"), 10);
      var end = parseInt(badge.getAttribute("data-end"), 10);
      if (isNaN(start) || isNaN(end)) return;

      var status = computeStatus(start, end, nowSeconds);
      if (badge.getAttribute("data-status") === status) return;

      badge.setAttribute("data-status", status);
      badge.classList.remove("status-upcoming", "status-ongoing", "status-finished");
      badge.classList.add("status-" + status);
      badge.textContent = STATUS_TEXT[status] || status;

      badge.style.animation = "none";
      void badge.offsetWidth;
      badge.style.animation = "";
    });
  }

  refreshStatuses();
  setInterval(refreshStatuses, 30000);
});
