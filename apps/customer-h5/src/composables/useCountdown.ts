import { computed, onUnmounted, ref } from 'vue'

export function useCountdown() {
  const seconds = ref(0)
  let timer: number | undefined
  const text = computed(() => {
    if (seconds.value <= 0) return '已截止'
    const hours = Math.floor(seconds.value / 3600)
    const minutes = Math.floor((seconds.value % 3600) / 60)
    const secs = seconds.value % 60
    return [hours, minutes, secs].map((part) => String(part).padStart(2, '0')).join(':')
  })
  function start(deadline: string) {
    window.clearInterval(timer)
    const update = () => { seconds.value = Math.max(0, Math.floor((new Date(deadline.replace(' ', 'T')).getTime() - Date.now()) / 1000)) }
    update(); timer = window.setInterval(update, 1000)
  }
  onUnmounted(() => window.clearInterval(timer))
  return { seconds, text, start }
}
