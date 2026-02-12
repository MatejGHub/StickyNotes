function setupStickyNoteCloseHandler(wrapper, closeButton) {
  closeButton.addEventListener("click", () => {
    // Hide the save button when close button is clicked
    const saveButton = wrapper.querySelector(".sticky-comment-save");
    if (saveButton) {
      saveButton.classList.add("sticky-save-hidden");
      saveButton.classList.remove("sticky-save-visible");
    }

    if (wrapper.dataset.noteId) {
      fetch(my_ajax_object.ajax_url, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded",
        },
        body: (function () {
          var p = {
            action: "delete_sticky_note",
            note_id: wrapper.dataset.noteId,
            nonce: my_ajax_object.nonce,
          };
          if (my_ajax_object.is_guest === 1 && my_ajax_object.guest_token && my_ajax_object.guest_id) {
            p.guest_token = my_ajax_object.guest_token;
            p.guest_id = my_ajax_object.guest_id;
          }
          return new URLSearchParams(p);
        })(),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            wrapper.remove();
            // Decrement global count so limit recalculates correctly
            if (typeof window.currentNoteCount !== "undefined") {
              try {
                var n = Number(window.currentNoteCount || 0) - 1;
                window.currentNoteCount = n > 0 ? n : 0;
              } catch (_) {}
            }
            if (window.stickyFeedback) {
              window.stickyFeedback.showNotification(
                "Note deleted successfully!",
                "success",
                2000
              );
            }
            // Update bubble count
            if (window.updateBubbleCount) {
              window.updateBubbleCount();
            }
          } else {
            if (window.stickyFeedback) {
              window.stickyFeedback.showNotification(
                data.error || data.data?.message || "Failed to delete note",
                "error"
              );
            }
          }
        })
        .catch((error) => {
          if (window.stickyFeedback) {
            window.stickyFeedback.showNotification(
              "Failed to delete note",
              "error"
            );
          }
        });
    } else {
      wrapper.remove();
      if (typeof window.currentNoteCount !== "undefined") {
        try {
          var n2 = Number(window.currentNoteCount || 0) - 1;
          window.currentNoteCount = n2 > 0 ? n2 : 0;
        } catch (_) {}
      }
    }
  });
}

// Expose globally to ensure create-sticky-comment.js can call it
if (typeof window !== "undefined") {
  window.setupStickyNoteCloseHandler = setupStickyNoteCloseHandler;
}
