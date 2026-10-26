<?php if (!isset($user)) { http_response_code(404); exit; } ?>
<script>
<?php if ($showMissionAnimation): ?>
showMissionConfetti();
<?php endif; ?>

function showMissionConfetti() {
    const container = document.createElement('div');
    container.className = 'confetti-container';
    document.body.appendChild(container);
    const colors = ['#D4A843', '#5a3f72', '#F5D77B', '#10b981', '#72578B', '#fff'];

    for (let i = 0; i < 60; i++) {
        const piece = document.createElement('div');
        piece.className = 'confetti-piece';
        piece.style.left = Math.random() * 100 + '%';
        piece.style.top = '-10px';
        piece.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
        piece.style.width = (Math.random() * 8 + 4) + 'px';
        piece.style.height = (Math.random() * 8 + 4) + 'px';
        piece.style.borderRadius = Math.random() > 0.5 ? '50%' : '2px';
        piece.style.animationDuration = (Math.random() * 2 + 1.5) + 's';
        piece.style.animationDelay = (Math.random() * 1.5) + 's';
        container.appendChild(piece);
    }

    setTimeout(() => {
        container.remove();
    }, 4000);

    const toast = document.createElement('div');
    toast.className = 'custom-toast';
    toast.style.cssText = 'position:fixed;top:50%;left:50%;transform:translate(-50%,-50%);background:#72578B;color:#fff;padding:1.5rem 2rem;border-radius:16px;text-align:center;z-index:10000;box-shadow:0 10px 40px rgba(0,0,0,0.3);min-width:280px;';
    toast.innerHTML = '<div style="font-size:3rem;margin-bottom:0.5rem;">🎉</div>' +
        '<div style="font-size:1.25rem;font-weight:800;color:#D4A843;">Mission Complete!</div>' +
        '<div style="font-size:0.9rem;margin-top:0.25rem;">Hongera! Umepata bonus ya leo!</div>';
    document.body.appendChild(toast);
    setTimeout(() => {
        toast.style.transition = 'all 0.3s ease';
        toast.style.opacity = '0';
        toast.style.transform = 'translate(-50%, -50%) scale(0.8)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

<?php if ($missionStatus && !$missionStatus['completed'] && $missionStatus['has_mission']): ?>
function checkMissionProgress() {
    fetch('api/missions.php?action=status', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.has_mission && data.completed && data.just_completed) {
            const card = document.getElementById('missionCard');
            if (card) {
                card.dataset.completed = '1';
                document.getElementById('missionProgress')?.remove();
                const completedDiv = document.createElement('div');
                completedDiv.className = 'mission-completed';
                completedDiv.id = 'missionCompleted';
                completedDiv.innerHTML = '<div class="completed-icon"><i class="fas fa-check-circle"></i></div>' +
                    '<div><strong>Mission Complete!</strong><div style="font-size:0.75rem;color:#706878;">Hongera! Umepata bonus ya leo!</div></div>' +
                    '<div class="completed-badge">Done</div>';
                card.appendChild(completedDiv);
                showMissionConfetti();
            }
        } else if (data.has_mission && !data.completed) {
            if (document.getElementById('missionProgressText')) {
                document.getElementById('missionProgressText').textContent = data.progress || (data.completed_count + '/' + data.requirement_count);
                document.getElementById('missionProgressFill').style.width = data.percentage + '%';
                document.getElementById('missionPaidCount').textContent = data.completed_count || 0;
            }
        }
    })
    .catch(() => {});
}

setInterval(checkMissionProgress, 30000);
<?php endif; ?>


</script>
