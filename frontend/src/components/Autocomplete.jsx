import { useEffect, useRef, useState } from 'react'
import api from '../lib/api'

/**
 * Autocomplete genérico (plano de saúde, especialidade, CID-10).
 * Debounce 300ms, navegação por teclado, limite de resultados vem do backend.
 */
export default function Autocomplete({
  searchUrl,
  labelKey = 'name',
  valueKey = 'id',
  placeholder,
  onSelect,
  initialLabel = '',
  renderOption,
}) {
  const [query, setQuery] = useState(initialLabel)
  const [options, setOptions] = useState([])
  const [open, setOpen] = useState(false)
  const [highlighted, setHighlighted] = useState(-1)
  const debounceRef = useRef(null)
  const containerRef = useRef(null)

  useEffect(() => {
    setQuery(initialLabel)
  }, [initialLabel])

  useEffect(() => {
    function handleClickOutside(e) {
      if (containerRef.current && !containerRef.current.contains(e.target)) {
        setOpen(false)
      }
    }
    document.addEventListener('mousedown', handleClickOutside)
    return () => document.removeEventListener('mousedown', handleClickOutside)
  }, [])

  function handleChange(value) {
    setQuery(value)
    onSelect(null)

    if (debounceRef.current) clearTimeout(debounceRef.current)

    if (value.trim().length < 2) {
      setOptions([])
      setOpen(false)
      return
    }

    debounceRef.current = setTimeout(async () => {
      try {
        const { data } = await api.get(searchUrl, { params: { q: value } })
        setOptions(data)
        setOpen(data.length > 0)
        setHighlighted(-1)
      } catch {
        setOptions([])
      }
    }, 300)
  }

  function selectOption(option) {
    setQuery(option[labelKey])
    setOpen(false)
    onSelect(option)
  }

  function handleKeyDown(e) {
    if (!open) return
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setHighlighted((h) => Math.min(h + 1, options.length - 1))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setHighlighted((h) => Math.max(h - 1, 0))
    } else if (e.key === 'Enter' && highlighted >= 0) {
      e.preventDefault()
      selectOption(options[highlighted])
    } else if (e.key === 'Escape') {
      setOpen(false)
    }
  }

  return (
    <div className="autocomplete" ref={containerRef}>
      <input
        type="text"
        className="input"
        value={query}
        placeholder={placeholder}
        onChange={(e) => handleChange(e.target.value)}
        onKeyDown={handleKeyDown}
        onFocus={() => options.length > 0 && setOpen(true)}
        autoComplete="off"
      />
      {open && (
        <ul className="autocomplete-list">
          {options.map((option, idx) => (
            <li
              key={option[valueKey]}
              className={idx === highlighted ? 'autocomplete-item highlighted' : 'autocomplete-item'}
              onMouseDown={() => selectOption(option)}
            >
              {renderOption ? renderOption(option) : option[labelKey]}
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
