package syncdata

type EntityResult struct {
	Synced  int      `json:"synced"`
	Skipped int      `json:"skipped"`
	Failed  int      `json:"failed"`
	Errors  []string `json:"errors,omitempty"`
}

type IDMapping struct {
	values map[string]map[string]string
}

func NewIDMapping() *IDMapping {
	return &IDMapping{
		values: map[string]map[string]string{},
	}
}

func (m *IDMapping) Set(entity, sourceID, targetID string) {
	if sourceID == "" || targetID == "" {
		return
	}
	if _, ok := m.values[entity]; !ok {
		m.values[entity] = map[string]string{}
	}
	m.values[entity][sourceID] = targetID
}

func (m *IDMapping) Get(entity, sourceID string) (string, bool) {
	perEntity, ok := m.values[entity]
	if !ok {
		return "", false
	}
	targetID, ok := perEntity[sourceID]
	return targetID, ok
}
