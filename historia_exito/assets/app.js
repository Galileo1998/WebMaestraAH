document.querySelectorAll('.file-input').forEach(input => {
  input.addEventListener('change', () => {
    const box = document.getElementById(input.dataset.preview);
    const file = input.files[0];
    if (!file || !box) return;
    box.innerHTML = '';
    if (file.type.startsWith('image/')) {
      const img = document.createElement('img');
      img.src = URL.createObjectURL(file);
      img.alt = 'Vista previa';
      box.appendChild(img);
    } else {
      const span = document.createElement('span');
      span.textContent = file.name;
      box.appendChild(span);
    }
  });
});
