/* eslint-disable @typescript-eslint/no-explicit-any -- Google Maps is loaded dynamically and is not a package dependency. */
import { ref } from 'vue'

type AddressSelection = { address: string; lat: number; lng: number }
type GoogleWindow = Window & { google?: any }

let loader: Promise<any> | null = null

function loadGoogleMaps() {
  const key = import.meta.env.VITE_GOOGLE_MAPS_API_KEY?.trim()
  if (!key) return Promise.resolve(null)
  const existing = (window as GoogleWindow).google
  if (existing?.maps?.places) return Promise.resolve(existing)
  if (loader) return loader
  loader = new Promise((resolve, reject) => {
    const script = document.createElement('script')
    script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(key)}&libraries=places&v=weekly`
    script.async = true
    script.onload = () => resolve((window as GoogleWindow).google)
    script.onerror = () => reject(new Error('Google Maps 加载失败'))
    document.head.appendChild(script)
  })
  return loader
}

export function useGoogleMapsAddress(onSelect: (value: AddressSelection) => void) {
  const enabled = Boolean(import.meta.env.VITE_GOOGLE_MAPS_API_KEY?.trim())
  const loadingLocation = ref(false)
  const locationError = ref('')
  let google: any = null
  let map: any = null
  let marker: any = null
  let autocomplete: any = null

  function showPosition(lat: number, lng: number, mapElement?: HTMLElement | null) {
    if (!google || !mapElement) return
    const position = { lat, lng }
    map ||= new google.maps.Map(mapElement, { center: position, zoom: 16, mapTypeControl: false })
    map.setCenter(position)
    marker ||= new google.maps.Marker({ map })
    marker.setPosition(position)
  }

  async function setup(input: HTMLInputElement | null, mapElement: HTMLElement | null) {
    if (!enabled || !input) return
    google = await loadGoogleMaps()
    if (!google || autocomplete) return
    autocomplete = new google.maps.places.Autocomplete(input, {
      componentRestrictions: { country: 'hu' },
      fields: ['formatted_address', 'geometry'],
      types: ['address'],
    })
    autocomplete.addListener('place_changed', () => {
      const place = autocomplete.getPlace()
      const location = place.geometry?.location
      if (!location || !place.formatted_address) return
      const selected = { address: place.formatted_address, lat: location.lat(), lng: location.lng() }
      onSelect(selected)
      showPosition(selected.lat, selected.lng, mapElement)
    })
  }

  async function locate(mapElement: HTMLElement | null) {
    locationError.value = ''
    if (!navigator.geolocation) throw new Error('当前浏览器不支持定位')
    loadingLocation.value = true
    try {
      google = await loadGoogleMaps()
      if (!google) throw new Error('请先配置 Google Maps API Key')
      const position = await new Promise<GeolocationPosition>((resolve, reject) =>
        navigator.geolocation.getCurrentPosition(resolve, reject, {
          enableHighAccuracy: true,
          timeout: 10000,
          maximumAge: 60000,
        }),
      )
      const lat = position.coords.latitude
      const lng = position.coords.longitude
      const results = await new Promise<any[]>((resolve, reject) => {
        new google.maps.Geocoder().geocode({ location: { lat, lng } }, (rows: any[], status: string) =>
          status === 'OK' && rows?.length ? resolve(rows) : reject(new Error('无法识别当前位置地址')),
        )
      })
      const address = results[0].formatted_address
      onSelect({ address, lat, lng })
      showPosition(lat, lng, mapElement)
    } catch (error) {
      locationError.value = error instanceof Error ? error.message : '定位失败'
    } finally {
      loadingLocation.value = false
    }
  }

  return { enabled, loadingLocation, locationError, setup, locate }
}
