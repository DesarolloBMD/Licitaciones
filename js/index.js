
// Ir a la reportería desde el botón principal
document.addEventListener('DOMContentLoaded', () => {
  const btnReportes = document.getElementById('btn-ir-reportes');
  if (btnReportes) {
    btnReportes.addEventListener('click', () => {
      window.location.href = 'reporteria.html';
    });
  }
});
