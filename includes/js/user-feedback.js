/**
 * User Feedback System for Sticky Comments
 * Provides visual feedback instead of console logging
 */

// Create notification system
function showNotification(message, type = "info", duration = 3000) {
  // Remove existing notifications
  const existing = document.querySelector(".sticky-notification");
  if (existing) {
    existing.remove();
  }

  // Create notification element
  const notification = document.createElement("div");
  notification.className = `sticky-notification sticky-notification-${type}`;
  notification.textContent = message;

  // Add to page
  const mountRoot =
    typeof window.getStickyNotesContainer === "function"
      ? window.getStickyNotesContainer()
      : document.body;
  mountRoot.appendChild(notification);

  // Animate in
  setTimeout(() => {
    notification.classList.add("show");
    notification.classList.remove("hide");
  }, 10);

  // Auto remove
  setTimeout(() => {
    notification.classList.add("hide");
    notification.classList.remove("show");
    setTimeout(() => {
      if (notification.parentNode) {
        notification.remove();
      }
    }, 300);
  }, duration);
}

// Loading indicator
function showLoadingIndicator(element) {
  const loader = document.createElement("div");
  loader.className = "sticky-loading-spinner";
  loader.innerHTML = "⟳";

  element.classList.add("sticky-loader-overlay");
  element.appendChild(loader);

  return loader;
}

function hideLoadingIndicator(loader) {
  if (loader && loader.parentNode) {
    loader.remove();
  }
}

// Make functions available globally
window.stickyFeedback = {
  showNotification,
  showLoadingIndicator,
  hideLoadingIndicator,
};
