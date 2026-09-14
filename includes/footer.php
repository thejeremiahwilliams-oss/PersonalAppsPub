<?php
// includes/footer.php
?>
    </div> <!-- Close .container -->
    
    <footer>
        &copy; <?= date('Y') ?> PersonalApps. All rights reserved.
    </footer>

    <!-- Global Toast Notification for Sharing -->
    <div id="global-toast" style="display: none; position: fixed; bottom: 30px; right: 30px; background: var(--success); color: white; padding: 12px 24px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); z-index: 9999; font-weight: bold; align-items: center; gap: 10px;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path></svg>
        Link Copied to Clipboard!
    </div>

    <script>
        // Universal Share Function
        function generateShareLink(type, id) {
            if (!id) {
                alert("Please save this item first before sharing.");
                return;
            }
            
            const formData = new FormData();
            formData.append('type', type);
            formData.append('id', id);

            fetch('api_share.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    navigator.clipboard.writeText(data.link).then(() => {
                        const toast = document.getElementById('global-toast');
                        toast.style.display = 'flex';
                        setTimeout(() => { toast.style.display = 'none'; }, 3000);
                    });
                } else {
                    alert("Error generating share link: " + data.error);
                }
            })
            .catch(err => console.error("Share error:", err));
        }
    </script>
</body>
</html>