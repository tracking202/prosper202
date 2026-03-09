package shell

import (
	"reflect"
	"testing"
)

func TestTokenizeLine(t *testing.T) {
	tests := []struct {
		name    string
		input   string
		want    []string
		wantErr bool
	}{
		{
			name:  "simple command",
			input: "campaign list",
			want:  []string{"campaign", "list"},
		},
		{
			name:  "command with flags",
			input: "campaign list --limit 10 --json",
			want:  []string{"campaign", "list", "--limit", "10", "--json"},
		},
		{
			name:  "double quoted argument",
			input: `campaign create --name "Black Friday Offer"`,
			want:  []string{"campaign", "create", "--name", "Black Friday Offer"},
		},
		{
			name:  "single quoted argument",
			input: `campaign create --name 'Black Friday Offer'`,
			want:  []string{"campaign", "create", "--name", "Black Friday Offer"},
		},
		{
			name:  "empty input",
			input: "",
			want:  nil,
		},
		{
			name:  "whitespace only",
			input: "   \t  ",
			want:  nil,
		},
		{
			name:  "extra whitespace between tokens",
			input: "campaign   list   --json",
			want:  []string{"campaign", "list", "--json"},
		},
		{
			name:    "unterminated double quote",
			input:   `campaign create --name "Black Friday`,
			wantErr: true,
		},
		{
			name:    "unterminated single quote",
			input:   `campaign create --name 'Black Friday`,
			wantErr: true,
		},
		{
			name:  "mixed quotes",
			input: `report summary --period "last 7" --name 'test campaign'`,
			want:  []string{"report", "summary", "--period", "last 7", "--name", "test campaign"},
		},
		{
			name:  "equals-style flags",
			input: "--limit=10 --offset=20",
			want:  []string{"--limit=10", "--offset=20"},
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := TokenizeLine(tt.input)
			if (err != nil) != tt.wantErr {
				t.Errorf("TokenizeLine() error = %v, wantErr %v", err, tt.wantErr)
				return
			}
			if !tt.wantErr && !reflect.DeepEqual(got, tt.want) {
				t.Errorf("TokenizeLine() = %v, want %v", got, tt.want)
			}
		})
	}
}

func TestSplitCommands(t *testing.T) {
	tests := []struct {
		name    string
		input   string
		want    []string
		wantErr bool
	}{
		{
			name:  "single command",
			input: "campaign list",
			want:  []string{"campaign list"},
		},
		{
			name:  "semicolon separated",
			input: "campaign list; report summary --period today",
			want:  []string{"campaign list", "report summary --period today"},
		},
		{
			name:  "newline separated",
			input: "campaign list\nreport summary --period today",
			want:  []string{"campaign list", "report summary --period today"},
		},
		{
			name:  "mixed separators",
			input: "campaign list; report summary\nhealth",
			want:  []string{"campaign list", "report summary", "health"},
		},
		{
			name:  "semicolons inside quotes preserved",
			input: `campaign create --name "test; name"; health`,
			want:  []string{`campaign create --name "test; name"`, "health"},
		},
		{
			name:  "empty commands skipped",
			input: "campaign list;; ; report summary",
			want:  []string{"campaign list", "report summary"},
		},
		{
			name:  "comment lines skipped",
			input: "# this is a comment\ncampaign list\n# another comment\nhealth",
			want:  []string{"campaign list", "health"},
		},
		{
			name:  "empty input",
			input: "",
			want:  nil,
		},
		{
			name:    "unterminated quote across commands",
			input:   `campaign create --name "test; health`,
			wantErr: true,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			got, err := SplitCommands(tt.input)
			if (err != nil) != tt.wantErr {
				t.Errorf("SplitCommands() error = %v, wantErr %v", err, tt.wantErr)
				return
			}
			if !tt.wantErr && !reflect.DeepEqual(got, tt.want) {
				t.Errorf("SplitCommands() = %v, want %v", got, tt.want)
			}
		})
	}
}
