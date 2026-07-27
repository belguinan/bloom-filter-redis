const form = document.querySelector('[data-verification-form]')
const emailInput = document.querySelector('#email')

document.querySelectorAll('[data-email]').forEach((button) => {
  button.addEventListener('click', () => {
    emailInput.value = button.dataset.email
    emailInput.focus()
  })
})

form?.addEventListener('submit', () => {
  const button = form.querySelector('.submit')
  button.disabled = true
  button.querySelector('span').textContent = 'Vérification…'
})
